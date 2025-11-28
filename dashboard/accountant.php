<?php
/**
 * لوحة التحكم للمحاسب
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/approval_system.php';
require_once __DIR__ . '/../includes/table_styles.php';
require_once __DIR__ . '/../includes/production_reports.php';

requireRole('accountant');

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

// معالجة AJAX لجلب رصيد المندوب - يجب أن يكون في البداية قبل أي output
if ($page === 'financial' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_sales_rep_balance') {
    // تعطيل عرض الأخطاء في المتصفح
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    
    // تنظيف أي output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // إرسال headers
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    
    $response = ['success' => false, 'message' => ''];
    $salesRepId = isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : 0;
    
    if ($salesRepId <= 0) {
        $response['message'] = 'معرف المندوب غير صحيح';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }
    
    try {
        $balance = calculateSalesRepCashBalance($salesRepId);
        
        $salesRep = $db->queryOne(
            "SELECT id, username, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
            [$salesRepId]
        );
        
        if (empty($salesRep)) {
            $response['message'] = 'المندوب غير موجود أو غير نشط';
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            exit;
        }
        
        $response = [
            'success' => true,
            'balance' => floatval($balance),
            'sales_rep_name' => htmlspecialchars($salesRep['full_name'] ?? $salesRep['username'], ENT_QUOTES, 'UTF-8')
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch (Throwable $e) {
        // تسجيل الخطأ في ملف log بدلاً من إرساله للمتصفح
        error_log('Error getting sales rep balance [ID: ' . $salesRepId . ']: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        
        $response['message'] = 'حدث خطأ أثناء جلب رصيد المندوب. يرجى المحاولة مرة أخرى.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    
    exit;
}

$customersModulePath = __DIR__ . '/../modules/sales/customers.php';
if (
    isset($_GET['ajax'], $_GET['action']) &&
    $_GET['ajax'] === 'purchase_history' &&
    $_GET['action'] === 'purchase_history'
) {
    if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
        define('CUSTOMERS_PURCHASE_HISTORY_AJAX', true);
    }
    if (file_exists($customersModulePath)) {
        include $customersModulePath;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'وحدة العملاء غير متاحة.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// معالجة طلب update_location قبل إرسال أي HTML
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'update_location') {
    $pageParam = $_GET['page'] ?? 'dashboard';
    if ($pageParam === 'customers') {
        // تنظيف أي output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // تحميل الملفات الأساسية
        if (!defined('CUSTOMERS_MODULE_BOOTSTRAPPED')) {
            require_once __DIR__ . '/../includes/config.php';
            require_once __DIR__ . '/../includes/db.php';
            require_once __DIR__ . '/../includes/auth.php';
            require_once __DIR__ . '/../includes/audit_log.php';
            require_once __DIR__ . '/../includes/path_helper.php';
            require_once __DIR__ . '/../includes/customer_history.php';
            require_once __DIR__ . '/../includes/invoices.php';
            require_once __DIR__ . '/../includes/salary_calculator.php';
            
            requireRole(['sales', 'accountant', 'manager']);
        }
        
        // تضمين وحدة customers التي تحتوي على معالج update_location
        if (file_exists($customersModulePath)) {
            define('CUSTOMERS_MODULE_BOOTSTRAPPED', true);
            if (!defined('CUSTOMERS_PURCHASE_HISTORY_AJAX')) {
                define('CUSTOMERS_PURCHASE_HISTORY_AJAX', true);
            }
            include $customersModulePath;
        } else {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'وحدة العملاء غير متاحة.'
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

$financialSuccess = '';
$financialError = '';
$financialFormData = [];

if ($page === 'financial') {
    if (isset($_SESSION['financial_success'])) {
        $financialSuccess = $_SESSION['financial_success'];
        unset($_SESSION['financial_success']);
    }
    if (isset($_SESSION['financial_error'])) {
        $financialError = $_SESSION['financial_error'];
        unset($_SESSION['financial_error']);
    }
    if (isset($_SESSION['financial_form_data'])) {
        $financialFormData = $_SESSION['financial_form_data'];
        unset($_SESSION['financial_form_data']);
    }
}

$reportsSuccess = '';
$reportsError = '';
if ($page === 'reports') {
    if (isset($_SESSION['reports_success'])) {
        $reportsSuccess = $_SESSION['reports_success'];
        unset($_SESSION['reports_success']);
    }
    if (isset($_SESSION['reports_error'])) {
        $reportsError = $_SESSION['reports_error'];
        unset($_SESSION['reports_error']);
    }
}

if ($page === 'pos') {
    $page = 'dashboard';
}

// معالجة AJAX قبل أي إخراج HTML - خاصة لصفحة مخزن أدوات التعبئة
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    // تحميل ملف packaging_warehouse.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}


if ($page === 'financial' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'collect_from_sales_rep') {
        $salesRepId = isset($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : 0;
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        
        if ($salesRepId <= 0) {
            $_SESSION['financial_error'] = 'يرجى اختيار مندوب صحيح.';
        } elseif ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ صحيح أكبر من الصفر.';
        } else {
            try {
                require_once __DIR__ . '/../includes/approval_system.php';
                $currentBalance = calculateSalesRepCashBalance($salesRepId);
                
                if ($amount > $currentBalance) {
                    $_SESSION['financial_error'] = 'المبلغ المطلوب (' . formatCurrency($amount) . ') أكبر من رصيد المندوب (' . formatCurrency($currentBalance) . ').';
                } else {
                    $db->beginTransaction();
                    
                    // الحصول على بيانات المندوب
                    $salesRep = $db->queryOne(
                        "SELECT id, username, full_name FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
                        [$salesRepId]
                    );
                    
                    if (empty($salesRep)) {
                        throw new Exception('المندوب غير موجود أو غير نشط');
                    }
                    
                    $salesRepName = $salesRep['full_name'] ?? $salesRep['username'];
                    $finalDescription = 'تحصيل من مندوب: ' . $salesRepName;
                    
                    // إضافة إيراد معتمد في financial_transactions
                    $db->execute(
                        "INSERT INTO financial_transactions (type, amount, supplier_id, description, reference_number, status, approved_by, created_by, approved_at)
                         VALUES (?, ?, NULL, NULL, ?, 'approved', ?, ?, NOW())",
                        [
                            'income',
                            $amount,
                            $finalDescription,
                            'COL-REP-' . $salesRepId . '-' . date('YmdHis'),
                            $currentUser['id'],
                            $currentUser['id']
                        ]
                    );
                    
                    $transactionId = $db->getLastInsertId();
                    
                    // تم إدراج الإيراد في financial_transactions
                    // لا حاجة لإدراج في collections لأن هذا تحصيل من المندوب وليس من عميل
                    
                    logAudit(
                        $currentUser['id'],
                        'collect_from_sales_rep',
                        'financial_transaction',
                        $transactionId,
                        null,
                        [
                            'sales_rep_id' => $salesRepId,
                            'sales_rep_name' => $salesRepName,
                            'amount' => $amount,
                        ]
                    );
                    
                    $db->commit();
                    
                    $_SESSION['financial_success'] = 'تم تحصيل ' . formatCurrency($amount) . ' من مندوب: ' . htmlspecialchars($salesRepName) . ' بنجاح.';
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Collect from sales rep failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء التحصيل: ' . $e->getMessage();
            }
        }
        
        $redirectTarget = strtok($_SERVER['REQUEST_URI'] ?? '?page=financial', '#');
        if (!headers_sent()) {
            header('Location: ' . $redirectTarget);
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        }
        exit;
    }

    if ($action === 'add_quick_expense') {
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $description = trim($_POST['description'] ?? '');
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $markAsApproved = isset($_POST['mark_as_approved']);

        $_SESSION['financial_form_data'] = [
            'amount' => $_POST['amount'] ?? '',
            'description' => $description,
            'reference_number' => $referenceNumber,
            'mark_as_approved' => $markAsApproved ? '1' : '0',
        ];

        if ($amount <= 0) {
            $_SESSION['financial_error'] = 'يرجى إدخال مبلغ مصروف صحيح.';
        } elseif ($description === '') {
            $_SESSION['financial_error'] = 'وصف المصروف مطلوب.';
        } else {
            try {
                $status = $markAsApproved ? 'approved' : 'pending';
                $approvedBy = $markAsApproved ? $currentUser['id'] : null;
                $approvedAt = $markAsApproved ? date('Y-m-d H:i:s') : null;

                $db->execute(
                    "INSERT INTO financial_transactions (type, amount, supplier_id, description, reference_number, status, approved_by, created_by, approved_at)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)",
                    [
                        'expense',
                        $amount,
                        $description,
                        $referenceNumber !== '' ? $referenceNumber : null,
                        $status,
                        $approvedBy,
                        $currentUser['id'],
                        $approvedAt
                    ]
                );

                $transactionId = $db->getLastInsertId();

                logAudit(
                    $currentUser['id'],
                    'quick_expense_create',
                    'financial_transaction',
                    $transactionId,
                    null,
                    [
                        'amount' => $amount,
                        'status' => $status,
                        'reference' => $referenceNumber !== '' ? $referenceNumber : null
                    ]
                );

                unset($_SESSION['financial_form_data']);

                $_SESSION['financial_success'] = $markAsApproved
                    ? 'تم تسجيل المصروف واعتماده فوراً.'
                    : 'تم تسجيل المصروف وإرساله للاعتماد.';
            } catch (Throwable $e) {
                error_log('Quick expense insertion failed: ' . $e->getMessage());
                $_SESSION['financial_error'] = 'حدث خطأ أثناء تسجيل المصروف. حاول مرة أخرى.';
            }
        }

        $redirectTarget = strtok($_SERVER['REQUEST_URI'] ?? '?page=financial', '#');
        if (!headers_sent()) {
            header('Location: ' . $redirectTarget);
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        }
        exit;
    }
}

if ($page === 'reports' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['reports_error'] = 'رمز الحماية غير صالح. يرجى إعادة المحاولة.';
        header('Location: accountant.php?page=reports');
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'send_monthly_production_report') {
        $reportMonth = isset($_POST['report_month']) ? max(1, min(12, (int) $_POST['report_month'])) : (int) date('n');
        $reportYear = isset($_POST['report_year']) ? max(2000, (int) $_POST['report_year']) : (int) date('Y');

        $result = sendMonthlyProductionDetailedReportToTelegram(
            $reportMonth,
            $reportYear,
            [
                'force' => true,
                'triggered_by' => $currentUser['id'] ?? null,
                'date_to' => date('Y-m-d'),
            ]
        );

        if (!empty($result['success'])) {
            $_SESSION['reports_success'] = $result['message'] ?? 'تم إرسال التقرير الشهري التفصيلي إلى Telegram.';
        } else {
            $_SESSION['reports_error'] = $result['message'] ?? 'تعذر إرسال التقرير الشهري التفصيلي.';
        }

        header('Location: accountant.php?page=reports');
        exit;
    }
}

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب';
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <!-- Page Header -->
                <div class="page-header">
                    <h2><i class="bi bi-speedometer2"></i><?php echo isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب'; ?></h2>
                </div>
                
                <!-- لوحة مالية مصغرة -->
                <div class="cards-grid">
                    <?php
                    $cashBalance = $db->queryOne(
                        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) as balance
                         FROM financial_transactions WHERE status = 'approved'"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo (isset($lang) && isset($lang['cash_balance'])) ? $lang['cash_balance'] : 'رصيد الخزينة'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($cashBalance['balance'] ?? 0); ?></div>
                    </div>
                    
                    <?php
                    $expenses = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'expense' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon red">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['expenses']) ? $lang['expenses'] : 'المصروفات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($expenses['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                    
                    <?php
                    // التحقق من وجود عمود status في جدول collections
                    $collectionsQuery = "SELECT COALESCE(SUM(amount), 0) as total FROM collections WHERE 1=1";
                    
                    // محاولة استخدام status إذا كان موجوداً
                    try {
                        $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                        if ($conn) {
                            $result = mysqli_query($conn, "SHOW COLUMNS FROM collections LIKE 'status'");
                            if ($result && mysqli_num_rows($result) > 0) {
                                $collectionsQuery .= " AND status = 'approved'";
                            }
                            mysqli_close($conn);
                        }
                    } catch (Exception $e) {
                        // إذا لم يكن status موجوداً، تجاهل
                    }
                    
                    $collectionsQuery .= " AND MONTH(date) = MONTH(NOW()) AND YEAR(date) = YEAR(NOW())";
                    $collections = $db->queryOne($collectionsQuery);
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['collections']) ? $lang['collections'] : 'التحصيلات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($collections['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                </div>
                
                <!-- آخر المعاملات -->
                <?php
                $pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
                $perPageTrans = 10;

                $validTypes = ['income', 'expense', 'transfer', 'payment'];
                $validStatuses = ['pending', 'approved', 'rejected'];

                $transactionTypeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
                if (!in_array($transactionTypeFilter, $validTypes, true)) {
                    $transactionTypeFilter = '';
                }

                $transactionStatusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
                if (!in_array($transactionStatusFilter, $validStatuses, true)) {
                    $transactionStatusFilter = '';
                }

                $fromDate = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
                    $fromDate = '';
                }

                $toDate = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
                    $toDate = '';
                }

                $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

                $whereParts = [];
                $whereParams = [];

                if ($transactionTypeFilter !== '') {
                    $whereParts[] = 'ft.type = ?';
                    $whereParams[] = $transactionTypeFilter;
                }

                if ($transactionStatusFilter !== '') {
                    $whereParts[] = 'ft.status = ?';
                    $whereParams[] = $transactionStatusFilter;
                }

                if ($fromDate !== '') {
                    $whereParts[] = 'DATE(ft.created_at) >= ?';
                    $whereParams[] = $fromDate;
                }

                if ($toDate !== '') {
                    $whereParts[] = 'DATE(ft.created_at) <= ?';
                    $whereParams[] = $toDate;
                }

                if ($searchTerm !== '') {
                    $whereParts[] = '(ft.description LIKE ? OR ft.reference_number LIKE ?)';
                    $likeValue = '%' . $searchTerm . '%';
                    $whereParams[] = $likeValue;
                    $whereParams[] = $likeValue;
                }

                $whereClause = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

                $countQuery = "SELECT COUNT(*) as total FROM financial_transactions ft $whereClause";
                $totalTransRow = $db->queryOne($countQuery, $whereParams);
                $totalTrans = $totalTransRow['total'] ?? 0;
                $totalPagesTrans = max(1, (int) ceil($totalTrans / $perPageTrans));
                if ($pageNum > $totalPagesTrans) {
                    $pageNum = $totalPagesTrans;
                }
                $offsetTrans = ($pageNum - 1) * $perPageTrans;

                $transactionsQuery = "
                    SELECT ft.*, creator.full_name AS creator_name, creator.username AS creator_username
                    FROM financial_transactions ft
                    LEFT JOIN users creator ON ft.created_by = creator.id
                    $whereClause
                    ORDER BY ft.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                $transactionsParams = array_merge($whereParams, [$perPageTrans, $offsetTrans]);
                $transactions = $db->query($transactionsQuery, $transactionsParams);

                $filterQueryParams = ['page' => 'financial'];
                if ($transactionTypeFilter !== '') {
                    $filterQueryParams['type'] = $transactionTypeFilter;
                }
                if ($transactionStatusFilter !== '') {
                    $filterQueryParams['status'] = $transactionStatusFilter;
                }
                if ($fromDate !== '') {
                    $filterQueryParams['from_date'] = $fromDate;
                }
                if ($toDate !== '') {
                    $filterQueryParams['to_date'] = $toDate;
                }
                if ($searchTerm !== '') {
                    $filterQueryParams['search'] = $searchTerm;
                }
                $filterBaseQuery = http_build_query($filterQueryParams);
                ?>
                
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>المعاملات المالية (<?php echo $totalTrans; ?>)</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end mb-4">
                            <input type="hidden" name="page" value="financial">
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">نوع المعاملة</label>
                                <select name="type" class="form-select">
                                    <option value="" <?php echo $transactionTypeFilter === '' ? 'selected' : ''; ?>>جميع الأنواع</option>
                                    <option value="income" <?php echo $transactionTypeFilter === 'income' ? 'selected' : ''; ?>><?php echo $lang['income'] ?? 'إيراد'; ?></option>
                                    <option value="expense" <?php echo $transactionTypeFilter === 'expense' ? 'selected' : ''; ?>><?php echo $lang['expense'] ?? 'مصروف'; ?></option>
                                    <option value="transfer" <?php echo $transactionTypeFilter === 'transfer' ? 'selected' : ''; ?>><?php echo $typeLabelMap['transfer']; ?></option>
                                    <option value="payment" <?php echo $transactionTypeFilter === 'payment' ? 'selected' : ''; ?>><?php echo $typeLabelMap['payment']; ?></option>
                                </select>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">حالة الاعتماد</label>
                                <select name="status" class="form-select">
                                    <option value="" <?php echo $transactionStatusFilter === '' ? 'selected' : ''; ?>>الكل</option>
                                    <option value="approved" <?php echo $transactionStatusFilter === 'approved' ? 'selected' : ''; ?>><?php echo $lang['approved'] ?? 'موافق عليه'; ?></option>
                                    <option value="pending" <?php echo $transactionStatusFilter === 'pending' ? 'selected' : ''; ?>><?php echo $lang['pending'] ?? 'قيد الانتظار'; ?></option>
                                    <option value="rejected" <?php echo $transactionStatusFilter === 'rejected' ? 'selected' : ''; ?>><?php echo $lang['rejected'] ?? 'مرفوض'; ?></option>
                                </select>
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">من تاريخ</label>
                                <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($fromDate, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-3 col-lg-2">
                                <label class="form-label">حتى تاريخ</label>
                                <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($toDate, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-4 col-lg-3">
                                <label class="form-label">بحث نصي</label>
                                <input type="text" name="search" class="form-control" placeholder="وصف أو رقم مرجعي" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-5 col-lg-3 d-flex gap-2">
                                <button type="submit" class="btn btn-primary flex-fill">
                                    <i class="bi bi-filter me-1"></i>تطبيق التصفية
                                </button>
                                <a href="?page=financial" class="btn btn-outline-secondary flex-fill">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>إعادة ضبط
                                </a>
                            </div>
                        </form>
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table table-striped table-hover align-middle text-nowrap">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>النوع</th>
                                        <th>المبلغ</th>
                                        <th>الوصف</th>
                                        <th>المرجع</th>
                                        <th>الحالة</th>
                                        <th>أنشئ بواسطة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">لا توجد معاملات مطابقة للمعايير الحالية</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $index => $trans): ?>
                                            <?php 
                                                $transactionType = $trans['type'] ?? '';
                                                $transactionColor = $typeColorMap[$transactionType] ?? 'secondary';
                                                $transactionLabel = $typeLabelMap[$transactionType] ?? $transactionType;
                                                $amountClass = in_array($transactionType, ['income'], true) ? 'text-success fw-semibold' : (in_array($transactionType, ['expense', 'payment'], true) ? 'text-danger fw-semibold' : 'text-primary fw-semibold');
                                                $statusColor = $trans['status'] === 'approved' ? 'success' : ($trans['status'] === 'rejected' ? 'danger' : 'warning');
                                                $creatorDisplay = $trans['creator_name'] ?: ($trans['creator_username'] ?? '-');
                                            ?>
                                            <tr>
                                                <td><?php echo $offsetTrans + $index + 1; ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $transactionColor; ?>">
                                                        <?php echo htmlspecialchars($transactionLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                </td>
                                                <td class="<?php echo $amountClass; ?>"><?php echo formatCurrency($trans['amount']); ?></td>
                                                <td class="text-truncate" style="max-width: 260px;" title="<?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars($trans['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($trans['reference_number'])): ?>
                                                        <?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $statusColor; ?>">
                                                        <?php echo $lang[$trans['status']] ?? $trans['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($creatorDisplay, ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo formatDateTime($trans['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPagesTrans > 1): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo $filterBaseQuery; ?>&amp;p=<?php echo $pageNum - 1; ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                <?php
                                $startPageTrans = max(1, $pageNum - 2);
                                $endPageTrans = min($totalPagesTrans, $pageNum + 2);
                                if ($startPageTrans > 1): ?>
                                    <li class="page-item"><a class="page-link" href="?<?php echo $filterBaseQuery; ?>&amp;p=1">1</a></li>
                                    <?php if ($startPageTrans > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $startPageTrans; $i <= $endPageTrans; $i++): ?>
                                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo $filterBaseQuery; ?>&amp;p=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($endPageTrans < $totalPagesTrans): ?>
                                    <?php if ($endPageTrans < $totalPagesTrans - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="?<?php echo $filterBaseQuery; ?>&amp;p=<?php echo $totalPagesTrans; ?>"><?php echo $totalPagesTrans; ?></a></li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $pageNum >= $totalPagesTrans ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo $filterBaseQuery; ?>&amp;p=<?php echo $pageNum + 1; ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($page === 'chat'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/chat/group_chat.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">وحدة الدردشة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'reports'): ?>
                <?php $reportsCsrfToken = generateCSRFToken(); ?>
                <div class="page-header mb-4">
                    <h2><i class="bi bi-bar-chart-fill me-2"></i>تقارير الإنتاج</h2>
                    <p class="text-muted mb-0">الوصول السريع للتقرير الشهري التفصيلي لخط الإنتاج وإرساله عبر Telegram.</p>
                </div>

                <?php if ($reportsError): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($reportsError, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($reportsSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($reportsSuccess, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="card shadow-sm">
                    <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div>
                            <h5 class="mb-1"><i class="bi bi-clipboard-pulse me-2 text-primary"></i>التقرير الشهري المفصل لخط الإنتاج</h5>
                            <p class="text-muted mb-0">يتضمن ملخص استهلاك المواد الخام وأدوات التعبئة بالإضافة إلى سجل التوريدات ويرسل مباشرة إلى قناة الإدارة على Telegram.</p>
                        </div>
                        <form method="post" class="d-flex flex-column flex-sm-row gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($reportsCsrfToken); ?>">
                            <input type="hidden" name="action" value="send_monthly_production_report">
                            <input type="hidden" name="report_month" value="<?php echo (int) date('n'); ?>">
                            <input type="hidden" name="report_year" value="<?php echo (int) date('Y'); ?>">
                            <button class="btn btn-primary">
                                <i class="bi bi-send-fill me-1"></i>إرسال التقرير الشهري المفصل
                            </button>
                        </form>
                    </div>
                </div>

            <?php elseif ($page === 'financial'): ?>
                <!-- صفحة الخزنة -->
                <div class="page-header mb-4 d-flex justify-content-between align-items-center">
                    <h2><i class="bi bi-safe me-2"></i><?php echo isset($lang['menu_financial']) ? $lang['menu_financial'] : 'الخزنة'; ?></h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#collectFromRepModal">
                        <i class="bi bi-cash-coin me-1"></i>تحصيل من مندوب
                    </button>
                </div>

                <?php if ($financialError): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($financialError, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($financialSuccess): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($financialSuccess, ENT_QUOTES, 'UTF-8'); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- لوحة مالية -->
                <div class="cards-grid">
                    <?php
                    $cashBalance = $db->queryOne(
                        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) as balance
                         FROM financial_transactions WHERE status = 'approved'"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo (isset($lang) && isset($lang['cash_balance'])) ? $lang['cash_balance'] : 'رصيد الخزينة'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($cashBalance['balance'] ?? 0); ?></div>
                    </div>
                    
                    <?php
                    $expenses = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'expense' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                        <div class="stat-card-header">
                            <div class="stat-card-icon red">
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['expenses']) ? $lang['expenses'] : 'المصروفات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($expenses['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                    <?php
                    $income = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) as total
                         FROM financial_transactions 
                         WHERE type = 'income' AND status = 'approved' 
                         AND MONTH(created_at) = MONTH(NOW())"
                    );
                    ?>
                    <div class="stat-card">
                    <div class="stat-card-header">
                            <div class="stat-card-icon green">
                                <i class="bi bi-arrow-up-circle"></i>
                            </div>
                        </div>
                        <div class="stat-card-title"><?php echo isset($lang['income']) ? $lang['income'] : 'الإيرادات'; ?></div>
                        <div class="stat-card-value"><?php echo formatCurrency($income['total'] ?? 0); ?></div>
                        <div class="stat-card-description">هذا الشهر</div>
                    </div>
                </div>
            
            <?php
            $treasurySummary = $db->queryOne("
                SELECT
                    SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END) AS approved_income,
                    SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END) AS approved_expense,
                    SUM(CASE WHEN type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END) AS approved_transfer,
                    SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END) AS approved_payment,
                    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) AS pending_total
                FROM financial_transactions
            ");
            
            $pendingStats = $db->queryOne("
                SELECT 
                    COUNT(*) AS total_pending,
                    SUM(amount) AS pending_amount
                FROM financial_transactions
                WHERE status = 'pending'
            ");
            
            $pendingTransactionsRaw = $db->query("
                SELECT id, type, amount, description, created_at 
                FROM financial_transactions
                WHERE status = 'pending'
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $pendingTransactions = is_array($pendingTransactionsRaw) ? $pendingTransactionsRaw : [];
            
            // حساب إجمالي المرتبات (المعتمدة والمدفوعة)
            $totalSalaries = 0.0;
            $salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
            if (!empty($salariesTableExists)) {
                $salariesResult = $db->queryOne(
                    "SELECT COALESCE(SUM(total_amount), 0) as total_salaries
                     FROM salaries
                     WHERE status IN ('approved', 'paid')"
                );
                $totalSalaries = (float) ($salariesResult['total_salaries'] ?? 0);
            }
            
            $netApprovedBalance = 
                ($treasurySummary['approved_income'] ?? 0) 
                - ($treasurySummary['approved_expense'] ?? 0)
                - ($treasurySummary['approved_payment'] ?? 0)
                - $totalSalaries;
            
            $approvedIncome = (float) ($treasurySummary['approved_income'] ?? 0);
            $approvedExpense = (float) ($treasurySummary['approved_expense'] ?? 0);
            $approvedPayment = (float) ($treasurySummary['approved_payment'] ?? 0);
            
            $movementTotal = $approvedIncome + $approvedExpense + $approvedPayment + $totalSalaries;
            $shareDenominator = $movementTotal > 0 ? $movementTotal : 1;
            $incomeShare = $shareDenominator > 0 ? round(($approvedIncome / $shareDenominator) * 100) : 0;
            $expenseShare = $shareDenominator > 0 ? round(($approvedExpense / $shareDenominator) * 100) : 0;
            $paymentShare = $shareDenominator > 0 ? round(($approvedPayment / $shareDenominator) * 100) : 0;
            $salariesShare = $shareDenominator > 0 ? round(($totalSalaries / $shareDenominator) * 100) : 0;
            $pendingCount = intval($pendingStats['total_pending'] ?? 0);
            $pendingAmount = (float) ($pendingStats['pending_amount'] ?? 0);
            $pendingPreview = array_slice($pendingTransactions, 0, 3);
            
            $typeLabelMap = [
                'income' => $lang['income'] ?? 'إيراد',
                'expense' => $lang['expense'] ?? 'مصروف',
                'transfer' => isset($lang['transfer']) ? $lang['transfer'] : 'تحويل',
                'payment' => isset($lang['payment']) ? $lang['payment'] : 'دفعة'
            ];
            
            $typeColorMap = [
                'income' => 'success',
                'expense' => 'danger',
                'transfer' => 'primary',
                'payment' => 'warning'
            ];
            ?>
            
            <div class="row g-3 mt-4">
                <div class="col-12 col-xxl-7">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>ملخص الخزنة</span>
                            <span class="badge bg-primary text-white">محدّث</span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                                <div>
                                    <span class="text-muted text-uppercase small">صافي الرصيد المعتمد</span>
                                    <div class="display-6 fw-bold mt-1"><?php echo formatCurrency($netApprovedBalance); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="badge bg-success text-white fw-semibold px-3 py-2">
                                        <?php echo formatCurrency($approvedIncome); ?> إيرادات
                                    </div>
                                    <div class="small text-muted mt-2">
                                        إجمالي الحركة المعتمدة: <?php echo formatCurrency($movementTotal); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-3 mt-3">
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">إيرادات معتمدة</span>
                                            <i class="bi bi-arrow-up-right-circle text-success"></i>
                                        </div>
                                        <div class="h5 text-success mt-2"><?php echo formatCurrency($approvedIncome); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo max(0, min(100, $incomeShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $incomeShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">مصروفات معتمدة</span>
                                            <i class="bi bi-arrow-down-right-circle text-danger"></i>
                                        </div>
                                        <div class="h5 text-danger mt-2"><?php echo formatCurrency($approvedExpense); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo max(0, min(100, $expenseShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $expenseShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">مدفوعات الموردين</span>
                                            <i class="bi bi-credit-card-2-back text-warning"></i>
                                        </div>
                                        <div class="h5 text-warning mt-2"><?php echo formatCurrency($approvedPayment); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo max(0, min(100, $paymentShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $paymentShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small">إجمالي المرتبات</span>
                                            <i class="bi bi-cash-stack text-danger"></i>
                                        </div>
                                        <div class="h5 text-danger mt-2"><?php echo formatCurrency($totalSalaries); ?></div>
                                        <div class="progress mt-3" style="height: 6px;">
                                            <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo max(0, min(100, $salariesShare)); ?>%;"></div>
                                        </div>
                                        <small class="text-muted d-block mt-2"><?php echo max(0, min(100, $salariesShare)); ?>% من إجمالي الحركة</small>
                                    </div>
                                </div>
                            </div>
                           
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-light fw-bold">
                            <i class="bi bi-pencil-square me-2 text-success"></i>تسجيل مصروف سريع
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="action" value="add_quick_expense">
                                <div class="col-12 col-sm-6">
                                    <label for="quickExpenseAmount" class="form-label">قيمة المصروف <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">ج.م</span>
                                        <input type="number" step="0.01" min="0.01" class="form-control" id="quickExpenseAmount" name="amount" required value="<?php echo htmlspecialchars($financialFormData['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <label for="quickExpenseReference" class="form-label">رقم مرجعي</label>
                                    <?php
                                    $generatedRef = 'REF-' . mt_rand(100000, 999999);?>
                                    <input type="text" class="form-control" id="quickExpenseReference" name="reference_number" value="<?php echo $generatedRef; ?>" readonly style="background:#f5f5f5; cursor:not-allowed;">
                                </div>
                                <div class="col-12">
                                    <label for="quickExpenseDescription" class="form-label">وصف المصروف <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="quickExpenseDescription" name="description" rows="3" required placeholder="أدخل تفاصيل المصروف..."><?php echo htmlspecialchars($financialFormData['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="quickExpenseApproved" name="mark_as_approved" value="1" <?php echo isset($financialFormData['mark_as_approved']) && $financialFormData['mark_as_approved'] === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="quickExpenseApproved">
                                            اعتماد المعاملة فوراً (يُستخدم عند تسجيل مصروف مؤكد)
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1">إذا تُرك غير محدد فسيتم إرسال المصروف للموافقة لاحقاً.</small>
                                </div>
                                <div class="col-12 d-flex justify-content-end gap-2">
                                    <button type="reset" class="btn btn-outline-secondary">تفريغ الحقول</button>
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-send me-1"></i>حفظ المصروف
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- جدول الحركات المالية -->
            <div class="card shadow-sm mt-4">
                <div class="card-header bg-light fw-bold">
                    <i class="bi bi-list-ul me-2 text-primary"></i>الحركات المالية
                </div>
                <div class="card-body">
                    <?php
                    // جلب جميع الحركات المالية
                    $financialTransactions = $db->query("
                        SELECT 
                            ft.*,
                            u1.full_name as created_by_name,
                            u2.full_name as approved_by_name
                        FROM financial_transactions ft
                        LEFT JOIN users u1 ON ft.created_by = u1.id
                        LEFT JOIN users u2 ON ft.approved_by = u2.id
                        ORDER BY ft.created_at DESC
                        LIMIT 100
                    ") ?: [];
                    
                    $typeLabels = [
                        'income' => 'إيراد',
                        'expense' => 'مصروف',
                        'transfer' => 'تحويل',
                        'payment' => 'دفعة'
                    ];
                    
                    $statusLabels = [
                        'pending' => 'معلق',
                        'approved' => 'معتمد',
                        'rejected' => 'مرفوض'
                    ];
                    
                    $statusColors = [
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger'
                    ];
                    ?>
                    
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead class="table-light">
                                <tr>
                                    <th>التاريخ</th>
                                    <th>النوع</th>
                                    <th>المبلغ</th>
                                    <th>الوصف</th>
                                    <th>الرقم المرجعي</th>
                                    <th>الحالة</th>
                                    <th>أنشأه</th>
                                    <th>اعتمده</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($financialTransactions)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bi bi-inbox me-2"></i>لا توجد حركات مالية حالياً
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($financialTransactions as $trans): ?>
                                        <?php 
                                        $isExpense = $trans['type'] === 'expense';
                                        $rowClass = $isExpense ? 'table-danger' : ($trans['type'] === 'income' ? 'table-success' : '');
                                        ?>
                                        <tr class="<?php echo $rowClass; ?>">
                                            <td><?php echo formatDateTime($trans['created_at']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $trans['type'] === 'income' ? 'success' : ($trans['type'] === 'expense' ? 'danger' : 'info'); ?>">
                                                    <?php echo htmlspecialchars($typeLabels[$trans['type']] ?? $trans['type'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td class="fw-bold <?php echo $trans['type'] === 'income' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $trans['type'] === 'income' ? '+' : '-'; ?><?php echo formatCurrency($trans['amount']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($trans['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if ($trans['reference_number']): ?>
                                                    <span class="text-muted small"><?php echo htmlspecialchars($trans['reference_number'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $statusColors[$trans['status']] ?? 'secondary'; ?>">
                                                    <?php echo htmlspecialchars($statusLabels[$trans['status']] ?? $trans['status'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($trans['created_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($trans['approved_by_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Modal تحصيل من مندوب -->
            <div class="modal fade" id="collectFromRepModal" tabindex="-1" aria-labelledby="collectFromRepModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="collectFromRepModalLabel">
                                <i class="bi bi-cash-coin me-2"></i>تحصيل من مندوب
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form method="POST" id="collectFromRepForm">
                            <input type="hidden" name="action" value="collect_from_sales_rep">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label for="salesRepSelect" class="form-label">اختر المندوب <span class="text-danger">*</span></label>
                                    <select class="form-select" id="salesRepSelect" name="sales_rep_id" required>
                                        <option value="">-- اختر المندوب --</option>
                                        <?php
                                        $salesReps = $db->query("
                                            SELECT id, username, full_name 
                                            FROM users 
                                            WHERE role = 'sales' AND status = 'active'
                                            ORDER BY full_name ASC, username ASC
                                        ") ?: [];
                                        foreach ($salesReps as $rep):
                                        ?>
                                            <option value="<?php echo $rep['id']; ?>">
                                                <?php echo htmlspecialchars($rep['full_name'] ?? $rep['username'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="repBalanceAmount" class="form-label">رصيد المندوب</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-wallet2 me-1"></i>رصيد المندوب</span>
                                        <input type="text" class="form-control" id="repBalanceAmount" readonly value="-- اختر مندوب أولاً --" style="background-color: #f8f9fa; font-weight: bold;">
                                        <span class="input-group-text">ج.م</span>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="collectAmount" class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">ج.م</span>
                                        <input type="number" step="0.01" min="0.01" class="form-control" id="collectAmount" name="amount" required placeholder="أدخل المبلغ">
                                    </div>
                                    <small class="text-muted">يجب أن يكون المبلغ أقل من أو يساوي رصيد المندوب</small>
                                </div>
                                
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" class="btn btn-primary" id="submitCollectBtn">
                                    <i class="bi bi-check-circle me-1"></i>تحصيل
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
                
            <?php elseif ($page === 'accountant_cash'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/cash_register.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة خزنة المحاسب غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'suppliers'): ?>
                <!-- صفحة الموردين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/suppliers.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'orders'): ?>
                <?php 
                $modulePath = __DIR__ . '/../modules/sales/customer_orders.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant orders module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة طلبات العملاء: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة طلبات العملاء غير متاحة حالياً</div>';
                }
                ?>
                
                
            <?php elseif ($page === 'invoices'): ?>
                <!-- صفحة الفواتير -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/invoices.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'collections'): ?>
                <!-- صفحة التحصيلات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/collections.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<h2><i class="bi bi-cash-coin me-2"></i>' . (isset($lang['collections']) ? $lang['collections'] : 'التحصيلات') . '</h2>';
                    echo '<div class="card shadow-sm"><div class="card-body"><p>صفحة التحصيلات - سيتم إضافتها</p></div></div>';
                }
                ?>
                
            <?php elseif ($page === 'salaries'): ?>
                <!-- صفحة الرواتب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/salaries.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    include __DIR__ . '/../modules/accountant/salaries.php';
                }
                ?>
                
            <?php elseif ($page === 'company_products'): ?>
                <!-- صفحة منتجات الشركة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/company_products.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant company products module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة منتجات الشركة: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة منتجات الشركة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'customers'): ?>
                <!-- صفحة العملاء -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/customers.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant customers module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة العملاء: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة العملاء غير متاحة حالياً</div>';
                }
                ?>

            <?php elseif ($page === 'rep_customers_view'): ?>
                <!-- صفحة عملاء المندوب -->
                <?php 
                $modulePath = __DIR__ . '/../modules/shared/rep_customers_view.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant rep customers view error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">تعذر تحميل صفحة عملاء المندوب: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة عرض عملاء المندوب غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'representatives_customers'): ?>
                <!-- صفحة عملاء المندوبين -->
                <?php 
                $modulePath = __DIR__ . '/../modules/manager/representatives_customers.php';
                if (file_exists($modulePath)) {
                    try {
                        include $modulePath;
                    } catch (Throwable $e) {
                        error_log('Accountant representatives customers module error: ' . $e->getMessage());
                        echo '<div class="alert alert-danger">حدث خطأ أثناء تحميل صفحة عملاء المندوبين: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                } else {
                    echo '<div class="alert alert-warning">صفحة عملاء المندوبين غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'attendance'): ?>
                <!-- صفحة الحضور -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'attendance_management'): ?>
                <!-- صفحة متابعة الحضور مع الإحصائيات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/attendance_management.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'advance_requests'): ?>
                <!-- صفحة طلبات السلفة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/advance_requests.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'packaging_warehouse'): ?>
                <!-- صفحة مخزن أدوات التعبئة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن أدوات التعبئة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'batch_reader'): ?>
                <!-- صفحة قارئ أرقام التشغيلات -->
                <div class="container-fluid p-0" style="height: 100vh; overflow: hidden;">
                    <iframe src="<?php echo getRelativeUrl('reader/index.php'); ?>" 
                            style="width: 100%; height: 100%; border: none; display: block;"></iframe>
                </div>
                
            <?php endif; ?>

<?php if ($page === 'financial'): ?>
<script>
// معالجة تحصيل من مندوب
document.addEventListener('DOMContentLoaded', function() {
    const salesRepSelect = document.getElementById('salesRepSelect');
    const repBalanceAmount = document.getElementById('repBalanceAmount');
    const collectAmount = document.getElementById('collectAmount');
    const collectForm = document.getElementById('collectFromRepForm');
    const submitBtn = document.getElementById('submitCollectBtn');
    
    if (salesRepSelect) {
        salesRepSelect.addEventListener('change', function() {
            const salesRepId = this.value;
            
            if (salesRepId && salesRepId !== '') {
                // إظهار loading state
                if (repBalanceAmount) {
                    repBalanceAmount.value = 'جاري التحميل...';
                    repBalanceAmount.style.color = '#6c757d';
                }
                
                // جلب رصيد المندوب
                fetch('?page=financial&ajax=get_sales_rep_balance&sales_rep_id=' + encodeURIComponent(salesRepId), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-cache'
                })
                .then(response => {
                    // التحقق من content type
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Invalid response type. Expected JSON but got: ' + contentType);
                    }
                    
                    // التحقق من حالة الاستجابة
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    
                    // محاولة parsing JSON
                    return response.text().then(text => {
                        if (!text || text.trim() === '') {
                            throw new Error('Empty response from server');
                        }
                        
                        try {
                            return JSON.parse(text);
                        } catch (parseError) {
                            console.error('JSON Parse Error:', parseError);
                            console.error('Response text:', text.substring(0, 200));
                            throw new Error('Invalid JSON response: ' + parseError.message);
                        }
                    });
                })
                .then(data => {
                    if (!data || typeof data !== 'object') {
                        throw new Error('Invalid response format');
                    }
                    
                    if (data.success) {
                        const balance = parseFloat(data.balance) || 0;
                        const formattedBalance = balance.toLocaleString('ar-EG', {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2
                        });
                        
                        if (repBalanceAmount) {
                            repBalanceAmount.value = formattedBalance;
                            repBalanceAmount.style.color = balance > 0 ? '#198754' : '#6c757d';
                        }
                        
                        // تعيين الحد الأقصى للمبلغ
                        if (collectAmount) {
                            collectAmount.max = balance;
                            collectAmount.setAttribute('data-max-balance', balance);
                        }
                    } else {
                        const errorMsg = data.message || 'فشل جلب رصيد المندوب';
                        if (repBalanceAmount) {
                            repBalanceAmount.value = 'خطأ: ' + errorMsg;
                            repBalanceAmount.style.color = '#dc3545';
                        }
                        console.error('Error:', errorMsg);
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    const errorMsg = error.message || 'حدث خطأ أثناء جلب رصيد المندوب';
                    if (repBalanceAmount) {
                        repBalanceAmount.value = 'خطأ في الاتصال';
                        repBalanceAmount.style.color = '#dc3545';
                    }
                });
            } else {
                // إعادة تعيين الحقل عند عدم اختيار مندوب
                if (repBalanceAmount) {
                    repBalanceAmount.value = '-- اختر مندوب أولاً --';
                    repBalanceAmount.style.color = '#6c757d';
                }
                if (collectAmount) {
                    collectAmount.max = '';
                    collectAmount.removeAttribute('data-max-balance');
                }
            }
        });
    }
    
    // التحقق من المبلغ قبل الإرسال
    if (collectForm) {
        collectForm.addEventListener('submit', function(e) {
            const amount = parseFloat(collectAmount.value);
            const maxBalance = parseFloat(collectAmount.getAttribute('data-max-balance') || '0');
            
            if (amount <= 0) {
                e.preventDefault();
                alert('يرجى إدخال مبلغ صحيح أكبر من الصفر');
                collectAmount.focus();
                return false;
            }
            
            if (maxBalance > 0 && amount > maxBalance) {
                e.preventDefault();
                alert('المبلغ المطلوب (' + amount.toLocaleString('ar-EG') + ' ج.م) أكبر من رصيد المندوب (' + maxBalance.toLocaleString('ar-EG') + ' ج.م)');
                collectAmount.focus();
                return false;
            }
            
            // تعطيل الزر أثناء الإرسال
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جاري التحصيل...';
        });
    }
    
    // إعادة تعيين النموذج عند إغلاق Modal
    const collectModal = document.getElementById('collectFromRepModal');
    if (collectModal) {
        collectModal.addEventListener('hidden.bs.modal', function() {
            if (collectForm) {
                collectForm.reset();
            }
            // إعادة تعيين حقل الرصيد
            if (repBalanceAmount) {
                repBalanceAmount.value = '-- اختر مندوب أولاً --';
                repBalanceAmount.style.color = '#6c757d';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>تحصيل';
            }
        });
    }
});
</script>
<?php endif; ?>

<script>
    // تمرير بيانات المستخدم للـ JavaScript
    window.currentUser = {
        id: <?php echo $currentUser['id']; ?>,
        role: '<?php echo htmlspecialchars($currentUser['role']); ?>'
    };
</script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
<script src="<?php echo ASSETS_URL; ?>js/reports.js"></script>
