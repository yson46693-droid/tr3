<?php
/**
 * صفحة خزنة الشركة - نسخة من صفحة المعاملات المالية للمحاسب
 */

 if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

// التأكد من وجود جدول accountant_transactions
function ensureAccountantTransactionsTable() {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    
    try {
        $db = db();
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `accountant_transactions` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','other') NOT NULL COMMENT 'نوع المعاملة',
                  `amount` decimal(15,2) NOT NULL COMMENT 'المبلغ',
                  `sales_rep_id` int(11) DEFAULT NULL COMMENT 'معرف المندوب (للتحصيل)',
                  `description` text NOT NULL COMMENT 'الوصف',
                  `reference_number` varchar(50) DEFAULT NULL COMMENT 'رقم مرجعي',
                  `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash' COMMENT 'طريقة الدفع',
                  `status` enum('pending','approved','rejected') DEFAULT 'approved' COMMENT 'الحالة',
                  `approved_by` int(11) DEFAULT NULL COMMENT 'من وافق',
                  `approved_at` timestamp NULL DEFAULT NULL COMMENT 'تاريخ الموافقة',
                  `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
                  `created_by` int(11) NOT NULL COMMENT 'من أنشأ السجل',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'تاريخ الإنشاء',
                  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'تاريخ التحديث',
                  PRIMARY KEY (`id`),
                  KEY `transaction_type` (`transaction_type`),
                  KEY `sales_rep_id` (`sales_rep_id`),
                  KEY `status` (`status`),
                  KEY `created_by` (`created_by`),
                  KEY `approved_by` (`approved_by`),
                  KEY `created_at` (`created_at`),
                  KEY `reference_number` (`reference_number`),
                  CONSTRAINT `accountant_transactions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                  CONSTRAINT `accountant_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `accountant_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول المعاملات المحاسبية'
            ");
        }
    } catch (Throwable $e) {
        error_log('Error creating accountant_transactions table: ' . $e->getMessage());
    }
}

// التأكد من وجود الجدول
ensureAccountantTransactionsTable();

// معالجة AJAX لجلب رصيد المندوب
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_sales_rep_balance') {
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
        error_log('Error getting sales rep balance [ID: ' . $salesRepId . ']: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        $response['message'] = 'حدث خطأ أثناء جلب رصيد المندوب. يرجى المحاولة مرة أخرى.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    
    exit;
}

$financialSuccess = '';
$financialError = '';
$financialFormData = [];

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

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                require_once __DIR__ . '/../../includes/approval_system.php';
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
                    $referenceNumber = 'COL-REP-' . $salesRepId . '-' . date('YmdHis');
                    
                    // إضافة تحصيل في جدول accountant_transactions
                    $db->execute(
                        "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, status, approved_by, created_by, approved_at)
                         VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW())",
                        [
                            'collection_from_sales_rep',
                            $amount,
                            $salesRepId,
                            $finalDescription,
                            $referenceNumber,
                            $currentUser['id'],
                            $currentUser['id']
                        ]
                    );
                    
                    $accountantTransactionId = $db->getLastInsertId();
                    $transactionId = $accountantTransactionId;
                    
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
        
        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
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

                // إذا كانت الحالة pending، إرسال طلب موافقة للمدير
                if ($status === 'pending') {
                    $approvalNotes = sprintf(
                        "مصروف سريع\nالمبلغ: %s ج.م\nالوصف: %s%s",
                        formatCurrency($amount),
                        $description,
                        $referenceNumber !== '' ? "\nالرقم المرجعي: " . $referenceNumber : ''
                    );
                    
                    $approvalResult = requestApproval('financial', $transactionId, $currentUser['id'], $approvalNotes);
                    
                    if (!$approvalResult['success']) {
                        error_log('Failed to create approval request for expense: ' . ($approvalResult['message'] ?? 'Unknown error'));
                    }
                }

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

        $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
        if (!headers_sent()) {
            header('Location: ' . $redirectTarget);
        } else {
            echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
        }
        exit;
    }
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['menu_financial']) ? $lang['menu_financial'] : 'خزنة الشركة';
?>
<?php include __DIR__ . '/../../templates/header.php'; ?>

<!-- صفحة الخزنة -->
<div class="company-cash-page" dir="rtl">
    <div class="page-header-wrapper mb-4">
        <div class="page-header d-flex justify-content-end align-items-center">
            <h2 class="mb-0"><i class="bi bi-safe me-2"></i><?php echo isset($lang['menu_financial']) ? $lang['menu_financial'] : 'خزنة الشركة'; ?></h2>
        </div>
    </div>

    <?php if ($financialError): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($financialError, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($financialSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show mb-4">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($financialSuccess, ENT_QUOTES, 'UTF-8'); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>


<?php
// حساب ملخص الخزينة من financial_transactions و accountant_transactions
$treasurySummary = $db->queryOne("
    SELECT
        (SELECT COALESCE(SUM(CASE WHEN type = 'income' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type IN ('collection_from_sales_rep', 'income') AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_income,
        (SELECT COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_expense,
        (SELECT COALESCE(SUM(CASE WHEN type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'transfer' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_transfer,
        (SELECT COALESCE(SUM(CASE WHEN type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'approved' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS approved_payment,
        (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) FROM financial_transactions) +
        (SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) FROM accountant_transactions) AS pending_total
");

// حساب المعاملات المعلقة
$pendingStats = $db->queryOne("
    SELECT 
        (SELECT COUNT(*) FROM financial_transactions WHERE status = 'pending') +
        (SELECT COUNT(*) FROM accountant_transactions WHERE status = 'pending') AS total_pending,
        (SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE status = 'pending') +
        (SELECT COALESCE(SUM(amount), 0) FROM accountant_transactions WHERE status = 'pending') AS pending_amount
");

$pendingTransactionsRaw = $db->query("
    SELECT id, type, amount, description, created_at 
    FROM financial_transactions
    WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 5
");
$pendingTransactions = is_array($pendingTransactionsRaw) ? $pendingTransactionsRaw : [];

// حساب إجمالي المرتبات
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

    <!-- Container رئيسي للصفحة -->
    <div class="company-cash-container">
        <!-- Grid Layout للعناصر الرئيسية -->
        <div class="company-cash-grid">
            <!-- قسم ملخص الخزنة -->
            <div class="treasury-summary-section">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-graph-up-arrow me-2 text-primary"></i>ملخص الخزنة</span>
                        <span class="badge bg-primary text-white">محدّث</span>
                    </div>
                    <div class="card-body">
                        <div class="balance-header d-flex flex-wrap justify-content-between align-items-start gap-4 mb-4">
                            <div class="balance-info">
                                <span class="text-muted text-uppercase small d-block mb-2">صافي الرصيد المعتمد</span>
                                <div class="display-4 fw-bold"><?php echo formatCurrency($netApprovedBalance); ?></div>
                            </div>
                            <div class="income-badge">
                                <div class="badge bg-success text-white fw-semibold px-4 py-3 fs-6">
                                    <?php echo formatCurrency($approvedIncome); ?> إيرادات
                                </div>
                            </div>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-header d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted small fw-semibold">إيرادات معتمدة</span>
                                    <i class="bi bi-arrow-up-right-circle text-success fs-5"></i>
                                </div>
                                <div class="stat-value h4 text-success fw-bold mb-3"><?php echo formatCurrency($approvedIncome); ?></div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo max(0, min(100, $incomeShare)); ?>%;"></div>
                                </div>
                                <small class="text-muted d-block"><?php echo max(0, min(100, $incomeShare)); ?>% من إجمالي الحركة</small>
                            </div>
                            <div class="stat-card">
                                <div class="stat-header d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted small fw-semibold">مصروفات معتمدة</span>
                                    <i class="bi bi-arrow-down-right-circle text-danger fs-5"></i>
                                </div>
                                <div class="stat-value h4 text-danger fw-bold mb-3"><?php echo formatCurrency($approvedExpense); ?></div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo max(0, min(100, $expenseShare)); ?>%;"></div>
                                </div>
                                <small class="text-muted d-block"><?php echo max(0, min(100, $expenseShare)); ?>% من إجمالي الحركة</small>
                            </div>
                            <div class="stat-card">
                                <div class="stat-header d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted small fw-semibold">مدفوعات الموردين</span>
                                    <i class="bi bi-credit-card-2-back text-warning fs-5"></i>
                                </div>
                                <div class="stat-value h4 text-warning fw-bold mb-3"><?php echo formatCurrency($approvedPayment); ?></div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo max(0, min(100, $paymentShare)); ?>%;"></div>
                                </div>
                                <small class="text-muted d-block"><?php echo max(0, min(100, $paymentShare)); ?>% من إجمالي الحركة</small>
                            </div>
                            <div class="stat-card">
                                <div class="stat-header d-flex justify-content-between align-items-center mb-3">
                                    <span class="text-muted small fw-semibold">إجمالي المرتبات</span>
                                    <i class="bi bi-cash-stack text-danger fs-5"></i>
                                </div>
                                <div class="stat-value h4 text-danger fw-bold mb-3"><?php echo formatCurrency($totalSalaries); ?></div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo max(0, min(100, $salariesShare)); ?>%;"></div>
                                </div>
                                <small class="text-muted d-block"><?php echo max(0, min(100, $salariesShare)); ?>% من إجمالي الحركة</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- قسم تسجيل المصروف -->
            <div class="expense-form-section">
                <button type="button" class="btn btn-primary btn-lg mb-3 w-100" data-bs-toggle="modal" data-bs-target="#collectFromRepModal">
                    <i class="bi bi-cash-coin me-2"></i>تحصيل من مندوب
                </button>
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light fw-bold">
                        <i class="bi bi-pencil-square me-2 text-success"></i>تسجيل مصروف سريع
                    </div>
                    <div class="card-body">
                        <form method="POST" class="expense-form">
                            <input type="hidden" name="action" value="add_quick_expense">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="quickExpenseAmount" class="form-label fw-semibold">قيمة المصروف <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text">ج.م</span>
                                        <input type="number" step="0.01" min="0.01" class="form-control" id="quickExpenseAmount" name="amount" required value="<?php echo htmlspecialchars($financialFormData['amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="quickExpenseReference" class="form-label fw-semibold">رقم مرجعي</label>
                                    <?php
                                    $generatedRef = 'REF-' . mt_rand(100000, 999999);?>
                                    <input type="text" class="form-control form-control-lg" id="quickExpenseReference" name="reference_number" value="<?php echo $generatedRef; ?>" readonly style="background:#f5f5f5; cursor:not-allowed;">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="quickExpenseDescription" class="form-label fw-semibold">وصف المصروف <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="quickExpenseDescription" name="description" rows="4" required placeholder="أدخل تفاصيل المصروف..."><?php echo htmlspecialchars($financialFormData['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="form-group">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="quickExpenseApproved" name="mark_as_approved" value="1" <?php echo isset($financialFormData['mark_as_approved']) && $financialFormData['mark_as_approved'] === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="quickExpenseApproved">
                                        اعتماد المعاملة فوراً (يُستخدم عند تسجيل مصروف مؤكد)
                                    </label>
                                </div>
                                <small class="text-muted d-block mt-1">إذا تُرك غير محدد فسيتم إرسال المصروف للموافقة لاحقاً.</small>
                            </div>
                            <div class="form-actions d-flex justify-content-end gap-2 mt-3">
                                <button type="reset" class="btn btn-outline-secondary btn-lg">تفريغ الحقول</button>
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-send me-1"></i>حفظ المصروف
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول الحركات المالية -->
    <div class="transactions-table-section mt-5">
        <div class="card shadow-sm">
    <div class="card-header bg-light fw-bold">
        <i class="bi bi-list-ul me-2 text-primary"></i>الحركات المالية
    </div>
    <div class="card-body">
        <?php
        // جلب جميع الحركات المالية من financial_transactions و accountant_transactions
        $financialTransactions = $db->query("
            SELECT 
                combined.*,
                u1.full_name as created_by_name,
                u2.full_name as approved_by_name
            FROM (
                SELECT 
                    id, 
                    type, 
                    amount, 
                    description, 
                    reference_number, 
                    status, 
                    created_by, 
                    approved_by,
                    created_at,
                    'financial_transactions' as source_table
                FROM financial_transactions
                UNION ALL
                SELECT 
                    id, 
                    CASE 
                        WHEN transaction_type = 'collection_from_sales_rep' THEN 'income'
                        WHEN transaction_type = 'expense' THEN 'expense'
                        WHEN transaction_type = 'income' THEN 'income'
                        WHEN transaction_type = 'transfer' THEN 'transfer'
                        WHEN transaction_type = 'payment' THEN 'payment'
                        ELSE 'other'
                    END as type,
                    amount, 
                    description, 
                    reference_number, 
                    status, 
                    created_by, 
                    approved_by,
                    created_at,
                    'accountant_transactions' as source_table
                FROM accountant_transactions
            ) as combined
            LEFT JOIN users u1 ON combined.created_by = u1.id
            LEFT JOIN users u2 ON combined.approved_by = u2.id
            ORDER BY combined.created_at DESC
            LIMIT 100
        ") ?: [];
        
        $typeLabels = [
            'income' => 'إيراد',
            'expense' => 'مصروف',
            'transfer' => 'تحويل',
            'payment' => 'دفعة',
            'other' => 'أخرى'
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
                const currentUrl = window.location.pathname;
                fetch(currentUrl + '?ajax=get_sales_rep_balance&sales_rep_id=' + encodeURIComponent(salesRepId), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-cache'
                })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Invalid response type. Expected JSON but got: ' + contentType);
                    }
                    
                    if (!response.ok) {
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    
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

<style>
/* ===== Company Cash Page Styles ===== */
.company-cash-page {
    width: 100%;
    max-width: 100%;
    direction: rtl;
}

.page-header-wrapper {
    width: 100%;
    display: flex;
    justify-content: flex-end;
    align-items: center;
}

.page-header {
    width: 100%;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 0;
}

.page-header h2 {
    margin: 0;
    font-size: 2rem;
    font-weight: 700;
}

/* Container رئيسي */
.company-cash-container {
    width: 100%;
    max-width: 100%;
    margin: 0 auto;
    padding: 0;
}

/* Grid Layout للعناصر الرئيسية */
.company-cash-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2rem;
    width: 100%;
    margin-top: 2rem;
}

/* قسم ملخص الخزنة */
.treasury-summary-section {
    width: 100%;
}

/* قسم تسجيل المصروف */
.expense-form-section {
    width: 100%;
}

/* Balance Header */
.balance-header {
    width: 100%;
    padding: 1.5rem 0;
    border-bottom: 2px solid #e9ecef;
    margin-bottom: 2rem;
}

.balance-info {
    flex: 1;
    min-width: 200px;
}

.income-badge {
    flex-shrink: 0;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    width: 100%;
}

.stat-card {
    border: 1px solid #dee2e6;
    border-radius: 0.75rem;
    padding: 1.5rem;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
    height: 100%;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-color: #adb5bd;
}

.stat-header {
    margin-bottom: 1rem;
}

.stat-value {
    margin-bottom: 1rem;
}

/* Expense Form */
.expense-form {
    width: 100%;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-group {
    width: 100%;
}

.form-actions {
    width: 100%;
    margin-top: 1.5rem;
}

/* Transactions Table Section */
.transactions-table-section {
    width: 100%;
    margin-top: 2.5rem;
}

/* Responsive Design */
@media (min-width: 992px) {
    .company-cash-grid {
        grid-template-columns: 1.4fr 1fr;
        gap: 2.5rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1400px) {
    .company-cash-grid {
        grid-template-columns: 1.75fr 1fr;
        gap: 3rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
    
    .form-row {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 991px) {
    .company-cash-grid {
        grid-template-columns: 1fr;
        gap: 2rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .balance-header {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 1.5rem;
    }
    
    .income-badge {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .page-header h2 {
        font-size: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-card {
        padding: 1rem;
    }
    
    .balance-header {
        padding: 1rem 0;
    }
    
    .display-4 {
        font-size: 2rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
    }
}

/* RTL Support */
[dir="rtl"] .company-cash-page {
    direction: rtl;
}

[dir="rtl"] .page-header-wrapper {
    justify-content: flex-end;
}

[dir="rtl"] .balance-header {
    flex-direction: row-reverse;
}

[dir="rtl"] .form-actions {
    justify-content: flex-end;
}

/* Dark Mode Support */
body.dark-mode .stat-card {
    background-color: #2d3748;
    border-color: #4a5568;
    color: #e2e8f0;
}

body.dark-mode .stat-card:hover {
    border-color: #718096;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
}

body.dark-mode .balance-header {
    border-bottom-color: #4a5568;
}
</style>

<?php include __DIR__ . '/../../templates/footer.php'; ?>
