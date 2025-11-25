<?php
/**
 * صفحة إدارة الجداول الزمنية للتحصيل
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/payment_schedules.php';
require_once __DIR__ . '/../../includes/audit_log.php';

require_once __DIR__ . '/table_styles.php';

requireRole('sales');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'due_date_from' => $_GET['due_date_from'] ?? '',
    'due_date_to' => $_GET['due_date_to'] ?? '',
    'overdue_only' => isset($_GET['overdue_only']) ? true : false
];

// إذا كان المستخدم مندوب مبيعات، عرض فقط جداوله
if ($currentUser['role'] === 'sales') {
    $filters['sales_rep_id'] = $currentUser['id'];
}

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'record_payment') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $amount = !empty($_POST['amount']) ? floatval($_POST['amount']) : null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($scheduleId > 0) {
            $result = recordPayment($scheduleId, $paymentDate, $amount, $notes);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'create_reminder') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        $daysBeforeDue = intval($_POST['days_before_due'] ?? 3);
        
        if ($scheduleId > 0) {
            if (createAutoReminder($scheduleId, $daysBeforeDue)) {
                $success = 'تم إنشاء التذكير بنجاح';
            } else {
                $error = 'حدث خطأ في إنشاء التذكير';
            }
        }
    } elseif ($action === 'create_schedule') {
        $customerId = intval($_POST['customer_id'] ?? 0);
        $amount = !empty($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $dueDate = $_POST['due_date'] ?? '';
        
        $dueDateValid = DateTime::createFromFormat('Y-m-d', $dueDate) !== false;
        
        if ($customerId <= 0) {
            $error = 'يجب اختيار عميل صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } elseif (!$dueDateValid) {
            $error = 'يرجى إدخال تاريخ استحقاق صحيح.';
        } else {
            try {
                $customer = $db->queryOne(
                    "SELECT id, name, balance FROM customers 
                     WHERE id = ? AND created_by = ? AND status = 'active' AND (balance IS NOT NULL AND balance > 0)",
                    [$customerId, $currentUser['id']]
                );
                
                if (!$customer) {
                    $error = 'لا يمكنك إنشاء موعد تحصيل لهذا العميل.';
                } else {
                    $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);
                    if ($dueDateObj < new DateTime('today')) {
                        $error = 'لا يمكن تحديد موعد تحصيل في تاريخ سابق.';
                    } else {
                        $insertResult = $db->execute(
                            "INSERT INTO payment_schedules 
                                (sale_id, customer_id, sales_rep_id, amount, due_date, installment_number, total_installments, status, created_by, created_at) 
                             VALUES (NULL, ?, ?, ?, ?, 1, 1, 'pending', ?, NOW())",
                            [
                                $customerId,
                                $currentUser['id'],
                                $amount,
                                $dueDate,
                                $currentUser['id']
                            ]
                        );
                        
                        $scheduleId = $insertResult['insert_id'] ?? null;
                        
                        if ($scheduleId) {
                            logAudit(
                                $currentUser['id'],
                                'create_payment_schedule_manual',
                                'payment_schedule',
                                $scheduleId,
                                null,
                                [
                                    'customer_id' => $customerId,
                                    'amount' => $amount,
                                    'due_date' => $dueDate
                                ]
                            );
                        }
                        
                        $success = 'تم إضافة موعد التحصيل بنجاح.';
                    }
                }
            } catch (Throwable $createScheduleError) {
                error_log('Create payment schedule error: ' . $createScheduleError->getMessage());
                $error = 'تعذر إنشاء موعد التحصيل، يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'update_schedule') {
        $scheduleId = intval($_POST['schedule_id'] ?? 0);
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $dueDate = $_POST['due_date'] ?? '';

        $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);

        if ($scheduleId <= 0) {
            $error = 'معرف الجدول غير صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } elseif (!$dueDateObj) {
            $error = 'يرجى إدخال تاريخ استحقاق صحيح.';
        } else {
            try {
                $schedule = $db->queryOne(
                    "SELECT * FROM payment_schedules WHERE id = ? AND sales_rep_id = ?",
                    [$scheduleId, $currentUser['id']]
                );

                if (!$schedule) {
                    $error = 'لا يمكنك تعديل هذا الجدول.';
                } elseif ($schedule['status'] === 'paid' || $schedule['status'] === 'cancelled') {
                    $error = 'لا يمكن تعديل جدول مدفوع أو ملغى.';
                } else {
                    $oldData = [
                        'amount' => $schedule['amount'],
                        'due_date' => $schedule['due_date'],
                        'status' => $schedule['status']
                    ];

                    $today = new DateTimeImmutable('today');
                    $newStatus = $schedule['status'];
                    if ($newStatus !== 'paid' && $newStatus !== 'cancelled') {
                        $newStatus = ($dueDateObj < $today) ? 'overdue' : 'pending';
                    }

                    $db->execute(
                        "UPDATE payment_schedules 
                         SET amount = ?, due_date = ?, status = ?, reminder_sent = 0, reminder_sent_at = NULL, updated_at = NOW()
                         WHERE id = ?",
                        [
                            $amount,
                            $dueDateObj->format('Y-m-d'),
                            $newStatus,
                            $scheduleId
                        ]
                    );

                    logAudit(
                        $currentUser['id'],
                        'update_payment_schedule',
                        'payment_schedule',
                        $scheduleId,
                        $oldData,
                        [
                            'amount' => $amount,
                            'due_date' => $dueDateObj->format('Y-m-d'),
                            'status' => $newStatus
                        ]
                    );

                    $success = 'تم تحديث موعد التحصيل بنجاح.';
                }
            } catch (Throwable $updateScheduleError) {
                error_log('Update payment schedule error: ' . $updateScheduleError->getMessage());
                $error = 'تعذر تحديث موعد التحصيل، يرجى المحاولة مرة أخرى.';
            }
        }
    }
}

// تحديث الحالات المتأخرة
updateOverdueSchedules();

// إرسال التذكيرات المعلقة
$sentReminders = sendPaymentReminders($currentUser['id']);

// الحصول على البيانات
$totalSchedules = $db->queryOne(
    "SELECT COUNT(*) as total FROM payment_schedules WHERE " . 
    ($currentUser['role'] === 'sales' ? "sales_rep_id = " . $currentUser['id'] : "1=1")
);
$totalSchedules = $totalSchedules['total'] ?? 0;
$totalPages = ceil($totalSchedules / $perPage);
$schedules = getPaymentSchedules($filters, $perPage, $offset);

$customers = $db->query(
    "SELECT id, name FROM customers 
     WHERE status = 'active' AND created_by = ? 
     ORDER BY name",
    [$currentUser['id']]
);

$debtorCustomers = $db->query(
    "SELECT id, name, balance FROM customers 
     WHERE status = 'active' AND created_by = ? 
       AND balance IS NOT NULL AND balance > 0
     ORDER BY name",
    [$currentUser['id']]
);
$hasDebtorCustomers = !empty($debtorCustomers);

// التحقق من وجود عمود sale_number في جدول sales
$saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
$hasSaleNumberColumn = !empty($saleNumberColumnCheck);

if ($hasSaleNumberColumn) {
    $sales = $db->query(
        "SELECT s.id, s.sale_number, c.name as customer_name 
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE s.status = 'approved'
         ORDER BY s.created_at DESC LIMIT 50"
    );
} else {
    $sales = $db->query(
        "SELECT s.id, s.id as sale_number, c.name as customer_name 
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE s.status = 'approved'
         ORDER BY s.created_at DESC LIMIT 50"
    );
}

// إحصائيات
$stats = [
    'total_pending' => 0,
    'total_overdue' => 0,
    'total_paid' => 0,
    'total_amount_pending' => 0,
    'total_amount_overdue' => 0
];

$statsQuery = $db->queryOne(
    "SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) as pending_amount,
        COALESCE(SUM(CASE WHEN status = 'overdue' THEN amount END), 0) as overdue_amount
     FROM payment_schedules" . 
    ($currentUser['role'] === 'sales' ? " WHERE sales_rep_id = " . $currentUser['id'] : "")
);

if ($statsQuery) {
    $stats = [
        'total_pending' => $statsQuery['pending_count'] ?? 0,
        'total_overdue' => $statsQuery['overdue_count'] ?? 0,
        'total_paid' => $statsQuery['paid_count'] ?? 0,
        'total_amount_pending' => $statsQuery['pending_amount'] ?? 0,
        'total_amount_overdue' => $statsQuery['overdue_amount'] ?? 0
    ];
}

// جدول محدد للعرض
$selectedSchedule = null;
if (isset($_GET['id'])) {
    $scheduleId = intval($_GET['id']);
    
    // التحقق من وجود عمود sale_number
    $saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
    $hasSaleNumberColumn = !empty($saleNumberColumnCheck);
    
    if ($hasSaleNumberColumn) {
        $selectedSchedule = $db->queryOne(
            "SELECT ps.*, s.sale_number, c.name as customer_name, c.phone as customer_phone,
                    u.full_name as sales_rep_name
             FROM payment_schedules ps
             LEFT JOIN sales s ON ps.sale_id = s.id
             LEFT JOIN customers c ON ps.customer_id = c.id
             LEFT JOIN users u ON ps.sales_rep_id = u.id
             WHERE ps.id = ?",
            [$scheduleId]
        );
    } else {
        $selectedSchedule = $db->queryOne(
            "SELECT ps.*, s.id as sale_number, c.name as customer_name, c.phone as customer_phone,
                    u.full_name as sales_rep_name
             FROM payment_schedules ps
             LEFT JOIN sales s ON ps.sale_id = s.id
             LEFT JOIN customers c ON ps.customer_id = c.id
             LEFT JOIN users u ON ps.sales_rep_id = u.id
             WHERE ps.id = ?",
            [$scheduleId]
        );
    }
}
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i>الجداول الزمنية للتحصيل</h2>
    <div class="d-flex align-items-center gap-2">
        <?php if ($sentReminders > 0): ?>
        <div class="alert alert-info mb-0 py-2 px-3">
            <i class="bi bi-info-circle me-2"></i>
            تم إرسال <?php echo $sentReminders; ?> تذكير
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal"
                <?php echo $hasDebtorCustomers ? '' : 'disabled'; ?>>
            <i class="bi bi-plus-circle me-2"></i>إضافة موعد تحصيل
        </button>
    </div>
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

<?php if (!$hasDebtorCustomers): ?>
    <div class="alert alert-warning">
        <i class="bi bi-info-circle-fill me-2"></i>
        لا توجد عملاء مدينون حالياً. قم بإضافة عميل أو تحديث رصيد العملاء ليظهر هنا.
    </div>
<?php endif; ?>

<?php if ($selectedSchedule): ?>
    <!-- عرض جدول محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">جدول التحصيل #<?php echo $selectedSchedule['id']; ?></h5>
            <a href="?page=payment_schedules" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">العميل:</th>
                            <td><?php echo htmlspecialchars($selectedSchedule['customer_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>مندوب المبيعات:</th>
                            <td><?php echo htmlspecialchars($selectedSchedule['sales_rep_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>رقم البيع:</th>
                            <td><?php echo htmlspecialchars($selectedSchedule['sale_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>المبلغ:</th>
                            <td><strong><?php echo formatCurrency($selectedSchedule['amount']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>تاريخ الاستحقاق:</th>
                            <td><?php echo formatDate($selectedSchedule['due_date']); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ الدفع:</th>
                            <td><?php echo $selectedSchedule['payment_date'] ? formatDate($selectedSchedule['payment_date']) : '-'; ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedSchedule['status'] === 'paid' ? 'success' : 
                                        ($selectedSchedule['status'] === 'overdue' ? 'danger' : 'warning'); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'paid' => 'مدفوع',
                                        'overdue' => 'متأخر',
                                        'cancelled' => 'ملغى'
                                    ];
                                    echo $statuses[$selectedSchedule['status']] ?? $selectedSchedule['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>القسط:</th>
                            <td><?php echo $selectedSchedule['installment_number']; ?> / <?php echo $selectedSchedule['total_installments']; ?></td>
                        </tr>
                        <tr>
                            <th>تم إرسال تذكير:</th>
                            <td><?php echo $selectedSchedule['reminder_sent'] ? 'نعم' : 'لا'; ?></td>
                        </tr>
                        <?php if ($selectedSchedule['reminder_sent_at']): ?>
                        <tr>
                            <th>تاريخ آخر تذكير:</th>
                            <td><?php echo formatDateTime($selectedSchedule['reminder_sent_at']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <?php if ($selectedSchedule['status'] !== 'paid' && $selectedSchedule['status'] !== 'cancelled'): ?>
                        <div class="mt-3">
                            <button class="btn btn-success" onclick="showPaymentModal(<?php echo $selectedSchedule['id']; ?>, <?php echo $selectedSchedule['amount']; ?>)">
                                <i class="bi bi-cash me-2"></i>تسجيل دفعة
                            </button>
                            <button class="btn btn-info" onclick="showReminderModal(<?php echo $selectedSchedule['id']; ?>)">
                                <i class="bi bi-bell me-2"></i>إنشاء تذكير
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- بطاقات الإحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="card-title">معلق</div>
            <div class="card-value"><?php echo $stats['total_pending']; ?></div>
            <div class="card-subtitle"><?php echo formatCurrency($stats['total_amount_pending']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="card-title">متأخر</div>
            <div class="card-value text-danger"><?php echo $stats['total_overdue']; ?></div>
            <div class="card-subtitle"><?php echo formatCurrency($stats['total_amount_overdue']); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-check-circle"></i></div>
            <div class="card-title">مدفوع</div>
            <div class="card-value text-success"><?php echo $stats['total_paid']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-calendar-event"></i></div>
            <div class="card-title">إجمالي الجداول</div>
            <div class="card-value"><?php echo $totalSchedules; ?></div>
        </div>
    </div>
</div>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="payment_schedules">
            <div class="col-md-3">
                <label class="form-label">العميل</label>
                <select class="form-select" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo ($filters['customer_id'] ?? '') == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="paid" <?php echo ($filters['status'] ?? '') === 'paid' ? 'selected' : ''; ?>>مدفوع</option>
                    <option value="overdue" <?php echo ($filters['status'] ?? '') === 'overdue' ? 'selected' : ''; ?>>متأخر</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="due_date_from" 
                       value="<?php echo htmlspecialchars($filters['due_date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="due_date_to" 
                       value="<?php echo htmlspecialchars($filters['due_date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="overdue_only" id="overdueOnly" 
                           <?php echo ($filters['overdue_only'] ?? false) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="overdueOnly">
                        المتأخرة فقط
                    </label>
                </div>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة الجداول الزمنية -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">قائمة الجداول الزمنية (<?php echo $totalSchedules; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>العميل</th>
                        <th>المبلغ</th>
                        <th>تاريخ الاستحقاق</th>
                        <th>القسط</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">لا توجد جداول زمنية</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr class="<?php echo $schedule['status'] === 'overdue' ? 'table-danger' : ''; ?>">
                                <td><?php echo htmlspecialchars($schedule['customer_name'] ?? '-'); ?></td>
                                <td><strong><?php echo formatCurrency($schedule['amount']); ?></strong></td>
                                <td>
                                    <?php echo formatDate($schedule['due_date']); ?>
                                    <?php if ($schedule['status'] === 'overdue'): ?>
                                        <span class="badge bg-danger ms-2">متأخر</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $schedule['installment_number']; ?> / <?php echo $schedule['total_installments']; ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $schedule['status'] === 'paid' ? 'success' : 
                                            ($schedule['status'] === 'overdue' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'paid' => 'مدفوع',
                                            'overdue' => 'متأخر',
                                            'cancelled' => 'ملغى'
                                        ];
                                        echo $statuses[$schedule['status']] ?? $schedule['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="?page=payment_schedules&id=<?php echo $schedule['id']; ?>" 
                                           class="btn btn-info" title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($schedule['status'] !== 'paid' && $schedule['status'] !== 'cancelled'): ?>
                                        <button class="btn btn-primary"
                                                data-schedule-id="<?php echo $schedule['id']; ?>"
                                                data-customer="<?php echo htmlspecialchars($schedule['customer_name'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-amount="<?php echo $schedule['amount']; ?>"
                                                data-due-date="<?php echo htmlspecialchars($schedule['due_date']); ?>"
                                                onclick="showEditScheduleModal(this)"
                                                title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-success" 
                                                onclick="showPaymentModal(<?php echo $schedule['id']; ?>, <?php echo $schedule['amount']; ?>)"
                                                title="تسجيل دفعة">
                                            <i class="bi bi-cash"></i>
                                        </button>
                                        <button class="btn btn-warning" 
                                                onclick="showReminderModal(<?php echo $schedule['id']; ?>)"
                                                title="إنشاء تذكير">
                                            <i class="bi bi-bell"></i>
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
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=payment_schedules&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=payment_schedules&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=payment_schedules&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=payment_schedules&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=payment_schedules&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة موعد تحصيل -->
<div class="modal fade" id="addScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة موعد تحصيل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_schedule">
                <div class="modal-body">
                    <?php if ($hasDebtorCustomers): ?>
                    <div class="mb-3">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" required>
                            <option value="">اختر العميل</option>
                            <?php foreach ($debtorCustomers as $customer): ?>
                                <option value="<?php echo (int) $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?> - رصيد مستحق: <?php echo formatCurrency($customer['balance']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">يتم عرض العملاء المدينين فقط من قائمة عملائي.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                               value="<?php echo (($_POST['action'] ?? '') === 'create_schedule') ? htmlspecialchars($_POST['amount'] ?? '') : ''; ?>"
                               required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">موعد التحصيل <span class="text-danger">*</span></label>
                        <input type="date" name="due_date" class="form-control"
                               value="<?php echo (($_POST['action'] ?? '') === 'create_schedule') ? htmlspecialchars($_POST['due_date'] ?? '') : date('Y-m-d'); ?>"
                               required>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        لا يوجد عملاء مدينون لإضافة موعد تحصيل. يرجى إضافة عميل مدين أولاً.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary" <?php echo $hasDebtorCustomers ? '' : 'disabled'; ?>>حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل موعد تحصيل -->
<div class="modal fade" id="editScheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل موعد التحصيل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_schedule">
                <input type="hidden" name="schedule_id" id="editScheduleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">العميل</label>
                        <input type="text" class="form-control" id="editScheduleCustomer" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" id="editScheduleAmount" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">موعد التحصيل <span class="text-danger">*</span></label>
                        <input type="date" name="due_date" class="form-control" id="editScheduleDueDate" required>
                    </div>
                    <div class="alert alert-info mb-0">
                        تعديل التاريخ سيُحدّث حالة الجدول تلقائياً ليتناسب مع الموعد الجديد.
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

<!-- Modal تسجيل دفعة -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تسجيل دفعة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="schedule_id" id="paymentScheduleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المبلغ <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" class="form-control" name="amount" id="paymentAmount" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاريخ الدفع <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تسجيل</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إنشاء تذكير -->
<div class="modal fade" id="reminderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء تذكير</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_reminder">
                <input type="hidden" name="schedule_id" id="reminderScheduleId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">عدد الأيام قبل موعد الاستحقاق <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="days_before_due" value="3" min="1" max="30" required>
                        <small class="text-muted">سيتم إرسال التذكير قبل موعد الاستحقاق بهذا العدد من الأيام</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showPaymentModal(scheduleId, amount) {
    document.getElementById('paymentScheduleId').value = scheduleId;
    document.getElementById('paymentAmount').value = amount;
    const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
    modal.show();
}

function showReminderModal(scheduleId) {
    document.getElementById('reminderScheduleId').value = scheduleId;
    const modal = new bootstrap.Modal(document.getElementById('reminderModal'));
    modal.show();
}

function showEditScheduleModal(button) {
    if (!button) {
        return;
    }

    const scheduleId = button.getAttribute('data-schedule-id') || '';
    const customer = button.getAttribute('data-customer') || '';
    const amount = button.getAttribute('data-amount') || '';
    const dueDate = button.getAttribute('data-due-date') || '';

    const modalEl = document.getElementById('editScheduleModal');
    if (!modalEl) {
        return;
    }

    const idInput = modalEl.querySelector('#editScheduleId');
    const customerInput = modalEl.querySelector('#editScheduleCustomer');
    const amountInput = modalEl.querySelector('#editScheduleAmount');
    const dueDateInput = modalEl.querySelector('#editScheduleDueDate');

    if (idInput) idInput.value = scheduleId;
    if (customerInput) customerInput.value = customer;
    if (amountInput) amountInput.value = amount;
    if (dueDateInput) dueDateInput.value = dueDate;

    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    const addScheduleModal = document.getElementById('addScheduleModal');
    if (addScheduleModal) {
        addScheduleModal.addEventListener('hidden.bs.modal', function () {
            const form = addScheduleModal.querySelector('form');
            if (form) {
                form.reset();
                const dueDateInput = form.querySelector('input[name=\"due_date\"]');
                if (dueDateInput) {
                    dueDateInput.value = '<?php echo date('Y-m-d'); ?>';
                }
            }
        });
    }

    const editScheduleModal = document.getElementById('editScheduleModal');
    if (editScheduleModal) {
        editScheduleModal.addEventListener('hidden.bs.modal', function () {
            const form = editScheduleModal.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    }
});
</script>

