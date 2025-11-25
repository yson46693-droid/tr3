<?php
/**
 * صفحة إدارة طلبات السلفة للمحاسب والمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/salary_calculator.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireAnyRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// التأكد من وجود جدول salary_advances
$salaryAdvancesTable = $db->queryOne("SHOW TABLES LIKE 'salary_advances'");
if (empty($salaryAdvancesTable)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `salary_advances` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL COMMENT 'الموظف',
              `amount` decimal(10,2) NOT NULL COMMENT 'مبلغ السلفة',
              `reason` text DEFAULT NULL COMMENT 'سبب السلفة',
              `request_date` date NOT NULL COMMENT 'تاريخ الطلب',
              `status` enum('pending','accountant_approved','manager_approved','rejected') DEFAULT 'pending' COMMENT 'حالة الطلب',
              `accountant_approved_by` int(11) DEFAULT NULL,
              `accountant_approved_at` timestamp NULL DEFAULT NULL,
              `manager_approved_by` int(11) DEFAULT NULL,
              `manager_approved_at` timestamp NULL DEFAULT NULL,
              `deducted_from_salary_id` int(11) DEFAULT NULL COMMENT 'الراتب الذي تم خصم السلفة منه',
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `status` (`status`),
              KEY `request_date` (`request_date`),
              KEY `accountant_approved_by` (`accountant_approved_by`),
              KEY `manager_approved_by` (`manager_approved_by`),
              KEY `deducted_from_salary_id` (`deducted_from_salary_id`),
              CONSTRAINT `salary_advances_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `salary_advances_ibfk_2` FOREIGN KEY (`accountant_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `salary_advances_ibfk_3` FOREIGN KEY (`manager_approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `salary_advances_ibfk_4` FOREIGN KEY (`deducted_from_salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating salary_advances table: " . $e->getMessage());
        $error = 'تعذر إنشاء جدول السلف. يرجى التواصل مع الدعم.';
    }
}

/**
 * إعادة توجيه بعد نجاح العملية مع رسالة
 */
function redirectWithSuccess($message, $role)
{
    preventDuplicateSubmission($message, ['page' => 'advance_requests'], null, $role);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $action = $_POST['action'] ?? '';
    $advanceId = intval($_POST['advance_id'] ?? 0);
    
    if ($advanceId <= 0) {
        $error = 'معرّف الطلب غير صحيح';
    } else {
        $advance = $db->queryOne(
            "SELECT sa.*, u.full_name, u.username 
             FROM salary_advances sa 
             LEFT JOIN users u ON sa.user_id = u.id 
             WHERE sa.id = ?",
            [$advanceId]
        );
        
        if (!$advance) {
            $error = 'طلب السلفة غير موجود';
        } else {
            switch ($action) {
                case 'accountant_approve':
                    if (!in_array($currentUser['role'], ['accountant', 'manager'])) {
                        $error = 'غير مصرح لك بهذا الإجراء';
                        break;
                    }
                    
                    if ($advance['status'] !== 'pending') {
                        $error = 'تمت معالجة هذا الطلب بالفعل';
                        break;
                    }
                    
                    $db->execute(
                        "UPDATE salary_advances 
                         SET status = 'accountant_approved',
                             accountant_approved_by = ?,
                             accountant_approved_at = NOW()
                         WHERE id = ?",
                        [$currentUser['id'], $advanceId]
                    );
                    
                    logAudit($currentUser['id'], 'accountant_approve_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount']
                    ]);
                    
                    // إشعار المديرين بالموافقة النهائية
                    $managers = $db->query("SELECT id FROM users WHERE role = 'manager' AND status = 'active'");
                    foreach ($managers as $manager) {
                        createNotification(
                            $manager['id'],
                            'طلب سلفة يحتاج موافقتك',
                            'طلب سلفة بمبلغ ' . number_format($advance['amount'], 2) . ' ج.م يحتاج موافقتك النهائية.',
                            'warning',
                            getDashboardUrl('manager') . '?page=salaries&view=advances',
                            false
                        );
                    }
                    
                    redirectWithSuccess('تم استلام طلب السلفة وإرساله للمدير للموافقة النهائية.', $currentUser['role']);
                    break;
                
                case 'manager_approve':
                    if ($currentUser['role'] !== 'manager') {
                        $error = 'غير مصرح لك بهذا الإجراء';
                        break;
                    }
                    
                    if (!in_array($advance['status'], ['pending', 'accountant_approved'])) {
                        $error = 'لا يمكن الموافقة على الطلب في حالته الحالية';
                        break;
                    }
                    
                    if (!empty($advance['deducted_from_salary_id'])) {
                        $error = 'تم خصم هذه السلفة من الراتب بالفعل.';
                        break;
                    }
                    
                    $resolution = salaryAdvanceResolveSalary($advance, $db);
                    if (!($resolution['success'] ?? false)) {
                        $error = $resolution['message'] ?? 'تعذر تحديد الراتب المناسب لخصم السلفة.';
                        break;
                    }

                    $salaryData = $resolution['salary'];
                    $salaryId = (int) ($resolution['salary_id'] ?? 0);

                    if ($salaryId <= 0) {
                        $error = 'تعذر تحديد الراتب المراد الخصم منه.';
                        break;
                    }

                    try {
                        $db->beginTransaction();

                        $deductionResult = salaryAdvanceApplyDeduction($advance, $salaryData, $db);
                        if (!($deductionResult['success'] ?? false)) {
                            $message = $deductionResult['message'] ?? 'تعذر تطبيق الخصم على الراتب.';
                            throw new Exception($message);
                        }

                        if ($advance['status'] === 'pending') {
                            $db->execute(
                                "UPDATE salary_advances 
                                 SET status = 'manager_approved',
                                     accountant_approved_by = ?,
                                     accountant_approved_at = NOW(),
                                     manager_approved_by = ?,
                                     manager_approved_at = NOW(),
                                     deducted_from_salary_id = ?
                                 WHERE id = ?",
                                [$currentUser['id'], $currentUser['id'], $salaryId, $advanceId]
                            );
                        } else {
                            $db->execute(
                                "UPDATE salary_advances 
                                 SET status = 'manager_approved',
                                     manager_approved_by = ?,
                                     manager_approved_at = NOW(),
                                     deducted_from_salary_id = ?
                                 WHERE id = ?",
                                [$currentUser['id'], $salaryId, $advanceId]
                            );
                        }

                        $db->commit();
                    } catch (Throwable $approveError) {
                        $db->rollback();
                        $error = $approveError->getMessage() ?: 'تعذر إتمام الموافقة على السلفة.';
                        break;
                    }
                    
                    logAudit($currentUser['id'], 'manager_approve_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount'],
                        'salary_id' => $salaryId
                    ]);
                    
                    createNotification(
                        $advance['user_id'],
                        'تمت الموافقة على طلب السلفة',
                        'تمت الموافقة على طلب السلفة بمبلغ ' . number_format($advance['amount'], 2) . ' ج.م. وتم خصمها من راتبك الحالي.',
                        'success',
                        null,
                        false
                    );
                    
                    redirectWithSuccess('تمت الموافقة على السلفة وتم خصمها من الراتب الحالي.', $currentUser['role']);
                    break;
                
                case 'reject':
                    if (!in_array($currentUser['role'], ['accountant', 'manager'])) {
                        $error = 'غير مصرح لك بهذا الإجراء';
                        break;
                    }
                    
                    $rejectionReason = trim($_POST['rejection_reason'] ?? '');
                    if ($rejectionReason === '') {
                        $error = 'يجب إدخال سبب الرفض';
                        break;
                    }
                    
                    if ($advance['status'] === 'manager_approved') {
                        $error = 'لا يمكن رفض سلفة تمت الموافقة عليها';
                        break;
                    }
                    
                    $db->execute(
                        "UPDATE salary_advances 
                         SET status = 'rejected',
                             notes = ?
                         WHERE id = ?",
                        [$rejectionReason, $advanceId]
                    );
                    
                    logAudit($currentUser['id'], 'reject_salary_advance', 'salary_advance', $advanceId, null, [
                        'user_id' => $advance['user_id'],
                        'amount' => $advance['amount'],
                        'reason' => $rejectionReason
                    ]);
                    
                    createNotification(
                        $advance['user_id'],
                        'تم رفض طلب السلفة',
                        'تم رفض طلب السلفة بمبلغ ' . number_format($advance['amount'], 2) . ' ج.م. السبب: ' . $rejectionReason,
                        'error',
                        null,
                        false
                    );
                    
                    redirectWithSuccess('تم رفض طلب السلفة.', $currentUser['role']);
                    break;
                
                default:
                    $error = 'إجراء غير معروف';
            }
        }
    }
}

// الفلاتر
$statusFilter = $_GET['status'] ?? 'pending';
$monthFilter = isset($_GET['month']) ? intval($_GET['month']) : 0;
$yearFilter = isset($_GET['year']) ? intval($_GET['year']) : 0;

$whereClauses = [];
$params = [];

if ($statusFilter && $statusFilter !== 'all') {
    $whereClauses[] = "sa.status = ?";
    $params[] = $statusFilter;
}

if ($monthFilter > 0) {
    $whereClauses[] = "MONTH(sa.request_date) = ?";
    $params[] = $monthFilter;
}

if ($yearFilter > 0) {
    $whereClauses[] = "YEAR(sa.request_date) = ?";
    $params[] = $yearFilter;
}

$whereClause = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// جلب الطلبات
$advanceRequests = $db->query(
    "SELECT sa.*, 
            u.full_name, u.username, u.role,
            accountant.full_name AS accountant_name,
            manager.full_name AS manager_name,
            salaries.total_amount AS salary_total
     FROM salary_advances sa
     LEFT JOIN users u ON sa.user_id = u.id
     LEFT JOIN users accountant ON sa.accountant_approved_by = accountant.id
     LEFT JOIN users manager ON sa.manager_approved_by = manager.id
     LEFT JOIN salaries ON sa.deducted_from_salary_id = salaries.id
     $whereClause
     ORDER BY sa.created_at DESC",
    $params
);

// إحصائيات عامة
$stats = [
    'pending' => $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'pending'")['count'] ?? 0,
    'accountant_approved' => $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'accountant_approved'")['count'] ?? 0,
    'manager_approved' => $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'manager_approved'")['count'] ?? 0,
    'rejected' => $db->queryOne("SELECT COUNT(*) as count FROM salary_advances WHERE status = 'rejected'")['count'] ?? 0,
    'pending_amount' => $db->queryOne("SELECT COALESCE(SUM(amount), 0) as total FROM salary_advances WHERE status IN ('pending','accountant_approved')")['total'] ?? 0
];

// إعدادات العرض
$statusLabels = [
    'pending' => ['class' => 'warning', 'text' => 'قيد الانتظار (بانتظار المحاسب)'],
    'accountant_approved' => ['class' => 'info', 'text' => 'تم الاستلام من المحاسب'],
    'manager_approved' => ['class' => 'success', 'text' => 'تمت الموافقة النهائية'],
    'rejected' => ['class' => 'danger', 'text' => 'مرفوض']
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-coin me-2"></i>طلبات السلفة</h2>
</div>

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

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon warning me-3">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="text-muted small">طلبات بانتظار المحاسب</div>
                    <div class="h4 mb-0"><?php echo $stats['pending']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon info me-3">
                    <i class="bi bi-person-check"></i>
                </div>
                <div>
                    <div class="text-muted small">بانتظار المدير</div>
                    <div class="h4 mb-0"><?php echo $stats['accountant_approved']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon success me-3">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="text-muted small">طلبات معتمدة</div>
                    <div class="h4 mb-0"><?php echo $stats['manager_approved']; ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-card-icon danger me-3">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div>
                    <div class="text-muted small">طلبات مرفوضة</div>
                    <div class="h4 mb-0"><?php echo $stats['rejected']; ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- فلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="advance_requests">
            <div class="col-md-4">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>قيد الانتظار</option>
                    <option value="accountant_approved" <?php echo $statusFilter === 'accountant_approved' ? 'selected' : ''; ?>>بانتظار المدير</option>
                    <option value="manager_approved" <?php echo $statusFilter === 'manager_approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">الشهر</label>
                <select class="form-select" name="month" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $monthFilter === $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">السنة</label>
                <select class="form-select" name="year" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $yearFilter === $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- قائمة طلبات السلفة -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة طلبات السلفة</h5>
        <?php if ($stats['pending'] + $stats['accountant_approved'] > 0): ?>
            <span class="badge bg-light text-dark">إجمالي المبالغ المعلقة: <?php echo formatCurrency($stats['pending_amount']); ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>التاريخ</th>
                        <th>المبلغ</th>
                        <th>السبب</th>
                        <th>الحالة</th>
                        <th>المحاسب</th>
                        <th>المدير</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($advanceRequests)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">لا توجد طلبات سلفة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($advanceRequests as $index => $request): ?>
                            <?php 
                            $statusInfo = $statusLabels[$request['status']] ?? ['class' => 'secondary', 'text' => $request['status']];
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['full_name'] ?? $request['username']); ?></strong>
                                    <br><small class="text-muted">@<?php echo htmlspecialchars($request['username']); ?></small>
                                </td>
                                <td>
                                    <?php echo formatDate($request['request_date']); ?>
                                    <br><small class="text-muted"><?php echo formatDateTime($request['created_at']); ?></small>
                                </td>
                                <td><strong class="text-primary"><?php echo formatCurrency($request['amount']); ?></strong></td>
                                <td>
                                    <?php if (!empty($request['reason'])): ?>
                                        <small><?php echo htmlspecialchars($request['reason']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $statusInfo['class']; ?>">
                                        <?php echo $statusInfo['text']; ?>
                                    </span>
                                    <?php if ($request['status'] === 'rejected' && !empty($request['notes'])): ?>
                                        <br><small class="text-danger">سبب الرفض: <?php echo htmlspecialchars($request['notes']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($request['accountant_name'])): ?>
                                        <small><?php echo htmlspecialchars($request['accountant_name']); ?><br><?php echo formatDateTime($request['accountant_approved_at']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($request['manager_name'])): ?>
                                        <small><?php echo htmlspecialchars($request['manager_name']); ?><br><?php echo formatDateTime($request['manager_approved_at']); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($request['status'] === 'pending' && in_array($currentUser['role'], ['accountant', 'manager'])): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form method="POST" onsubmit="return confirm('تأكيد استلام الطلب من قبل المحاسب؟');">
                                                <input type="hidden" name="action" value="accountant_approve">
                                                <input type="hidden" name="advance_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-info">
                                                    <i class="bi bi-check-circle me-1"></i>استلام
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="openRejectModal(<?php echo $request['id']; ?>)">
                                                <i class="bi bi-x-circle me-1"></i>رفض
                                            </button>
                                        </div>
                                    <?php elseif ($request['status'] === 'accountant_approved' && $currentUser['role'] === 'manager'): ?>
                                        <div class="d-flex flex-wrap gap-2">
                                            <form method="POST" onsubmit="return confirm('تأكيد الموافقة النهائية على السلفة؟');">
                                                <input type="hidden" name="action" value="manager_approve">
                                                <input type="hidden" name="advance_id" value="<?php echo $request['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success">
                                                    <i class="bi bi-check-circle-fill me-1"></i>موافقة
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="openRejectModal(<?php echo $request['id']; ?>)">
                                                <i class="bi bi-x-circle me-1"></i>رفض
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal الرفض -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>رفض طلب السلفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="advance_id" id="rejectAdvanceId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required placeholder="اذكر سبب الرفض"></textarea>
                    </div>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        سيتم إرسال السبب للموظف في إشعار فوري.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">رفض الطلب</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRejectModal(advanceId) {
    document.getElementById('rejectAdvanceId').value = advanceId;
    const rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
    rejectModal.show();
}
</script>

