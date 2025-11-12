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

requireRole('accountant');

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

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

if (in_array($page, ['pos', 'reports'], true)) {
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

                $transactionId = $db->lastInsertId();

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

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
$extraScripts = isset($extraScripts) && is_array($extraScripts) ? $extraScripts : [];
if ($page === 'group_chat') {
    $pageStylesheets[] = 'assets/css/group-chat.css';
    $extraScripts[] = getRelativeUrl('assets/js/group-chat.js');
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['accountant_dashboard']) ? $lang['accountant_dashboard'] : 'لوحة المحاسب';
if ($page === 'group_chat') {
    $pageTitle = $lang['menu_group_chat'] ?? 'الدردشة الجماعية';
}
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
                
            <?php elseif ($page === 'financial'): ?>
                <!-- صفحة الخزنة -->
                <div class="page-header mb-4">
                    <h2><i class="bi bi-safe me-2"></i><?php echo isset($lang['menu_financial']) ? $lang['menu_financial'] : 'الخزنة'; ?></h2>
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
            
            $netApprovedBalance = 
                ($treasurySummary['approved_income'] ?? 0) 
                - ($treasurySummary['approved_expense'] ?? 0)
                - ($treasurySummary['approved_payment'] ?? 0);
            
            $approvedIncome = (float) ($treasurySummary['approved_income'] ?? 0);
            $approvedExpense = (float) ($treasurySummary['approved_expense'] ?? 0);
            $approvedPayment = (float) ($treasurySummary['approved_payment'] ?? 0);
            $movementTotal = $approvedIncome + $approvedExpense + $approvedPayment;
            $shareDenominator = $movementTotal > 0 ? $movementTotal : 1;
            $incomeShare = $shareDenominator > 0 ? round(($approvedIncome / $shareDenominator) * 100) : 0;
            $expenseShare = $shareDenominator > 0 ? round(($approvedExpense / $shareDenominator) * 100) : 0;
            $paymentShare = $shareDenominator > 0 ? round(($approvedPayment / $shareDenominator) * 100) : 0;
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
                            </div>
                            <div class="border-top pt-3 mt-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                    <div>
                                        <span class="text-muted small">مبالغ بانتظار الاعتماد</span>
                                        <div class="fw-semibold text-warning mt-1"><?php echo formatCurrency($pendingAmount); ?></div>
                                    </div>
                                    <span class="badge bg-warning text-dark fw-semibold"><?php echo $pendingCount; ?> معاملات معلقة</span>
                                </div>
                                <?php if (!empty($pendingPreview)): ?>
                                    <ul class="list-unstyled small mt-3 mb-0">
                                        <?php foreach ($pendingPreview as $pending): ?>
                                            <?php 
                                                $pendingType = $pending['type'] ?? '';
                                                $pendingColor = $typeColorMap[$pendingType] ?? 'secondary';
                                                $pendingLabel = $typeLabelMap[$pendingType] ?? $pendingType;
                                            ?>
                                            <li class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                                <div class="text-truncate">
                                                    <span class="badge bg-<?php echo $pendingColor; ?> me-2">
                                                        <?php echo htmlspecialchars($pendingLabel, ENT_QUOTES, 'UTF-8'); ?>
                                                    </span>
                                                    <?php echo htmlspecialchars($pending['description'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                                <span class="text-muted text-nowrap"><?php echo formatDateTime($pending['created_at']); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted small mb-0 mt-3">لا توجد معاملات بانتظار الاعتماد.</p>
                                <?php endif; ?>
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
                                    <input type="text" class="form-control" id="quickExpenseReference" name="reference_number" placeholder="مثال: INV-2035" value="<?php echo htmlspecialchars($financialFormData['reference_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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
                
            <?php elseif ($page === 'group_chat'): ?>
                <?php include __DIR__ . '/../modules/chat/group_chat.php'; ?>

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
                
            <?php elseif ($page === 'inventory'): ?>
                <!-- صفحة المخزون -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/inventory.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php elseif ($page === 'inventory_movements'): ?>
                <!-- صفحة حركات المخزون -->
                <?php 
                $modulePath = __DIR__ . '/../modules/accountant/inventory_movements.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
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

