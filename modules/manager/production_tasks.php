<?php
/**
 * صفحة إرسال المهام لقسم الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole('manager');

$db = db();
$currentUser = getCurrentUser();
$error = '';
$success = '';

/**
 * تأكد من وجود جدول المهام (tasks)
 */
try {
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'tasks'");
    if (empty($tableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `tasks` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `title` varchar(255) NOT NULL,
              `description` text DEFAULT NULL,
              `assigned_to` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
              `status` enum('pending','received','in_progress','completed','cancelled') DEFAULT 'pending',
              `due_date` date DEFAULT NULL,
              `completed_at` timestamp NULL DEFAULT NULL,
              `received_at` timestamp NULL DEFAULT NULL,
              `started_at` timestamp NULL DEFAULT NULL,
              `related_type` varchar(50) DEFAULT NULL,
              `related_id` int(11) DEFAULT NULL,
              `product_id` int(11) DEFAULT NULL,
              `quantity` decimal(10,2) DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `assigned_to` (`assigned_to`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`),
              KEY `priority` (`priority`),
              KEY `due_date` (`due_date`),
              KEY `product_id` (`product_id`),
              CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
              CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `tasks_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    error_log('Manager task page table check error: ' . $e->getMessage());
}

/**
 * تحميل بيانات المستخدمين والمنتجات
 */
$productionUsers = [];
$products = [];

try {
    $productionUsers = $db->query("
        SELECT id, full_name
        FROM users
        WHERE status = 'active' AND role = 'production'
        ORDER BY full_name
    ");
} catch (Exception $e) {
    error_log('Manager task page users query error: ' . $e->getMessage());
}

$allowedTypes = ['general', 'production', 'quality', 'maintenance'];
$allowedPriorities = ['low', 'normal', 'high', 'urgent'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_production_task') {
        $taskType = $_POST['task_type'] ?? 'general';
        $taskType = in_array($taskType, $allowedTypes, true) ? $taskType : 'general';

        $title = trim($_POST['title'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $priority = $_POST['priority'] ?? 'normal';
        $priority = in_array($priority, $allowedPriorities, true) ? $priority : 'normal';
        $dueDate = $_POST['due_date'] ?? '';
        $assignees = $_POST['assigned_to'] ?? [];

        if (!is_array($assignees)) {
            $assignees = [$assignees];
        }

        $assignees = array_unique(array_filter(array_map('intval', $assignees)));
        $allowedAssignees = array_map(function ($user) {
            return (int)($user['id'] ?? 0);
        }, $productionUsers);
        $assignees = array_values(array_intersect($assignees, $allowedAssignees));

        if (empty($assignees)) {
            $error = 'يجب اختيار عامل واحد على الأقل لاستلام المهمة.';
        } elseif ($taskType === 'general' && $title === '') {
            $error = 'يرجى إدخال عنوان للمهمة.';
        } elseif ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $error = 'صيغة تاريخ الاستحقاق غير صحيحة.';
        } else {
            try {
                $db->beginTransaction();

                $relatedTypeValue = 'manager_' . $taskType;

                if ($title === '') {
                    $title = $taskType === 'production' ? 'مهمة إنتاج جديدة' : 'مهمة جديدة';
                }

                $insertedTaskIds = [];
                foreach ($assignees as $assignedId) {
                    $columns = ['title', 'description', 'created_by', 'priority', 'status', 'related_type'];
                    $values = [$title, $details ?: null, $currentUser['id'], $priority, 'pending', $relatedTypeValue];
                    $placeholders = ['?', '?', '?', '?', '?', '?'];

                    if ($assignedId > 0) {
                        $columns[] = 'assigned_to';
                        $values[] = $assignedId;
                        $placeholders[] = '?';
                    }

                    if ($dueDate) {
                        $columns[] = 'due_date';
                        $values[] = $dueDate;
                        $placeholders[] = '?';
                    }

                    $sql = "INSERT INTO tasks (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $result = $db->execute($sql, $values);
                    $taskId = $result['insert_id'] ?? 0;

                    if ($taskId <= 0) {
                        throw new Exception('تعذر إنشاء المهمة.');
                    }

                    $insertedTaskIds[] = $taskId;

                    logAudit(
                        $currentUser['id'],
                        'create_production_task',
                        'tasks',
                        $taskId,
                        null,
                        [
                            'task_type' => $taskType,
                            'assigned_to' => $assignedId,
                            'priority' => $priority,
                            'due_date' => $dueDate
                        ]
                    );

                    $notificationTitle = 'مهمة جديدة من الإدارة';
                    $notificationMessage = $title;

                    try {
                        createNotification(
                            $assignedId,
                            $notificationTitle,
                            $notificationMessage,
                            'info',
                            getRelativeUrl('production.php?page=tasks')
                        );
                    } catch (Exception $notificationException) {
                        error_log('Manager task notification error: ' . $notificationException->getMessage());
                    }
                }

                $db->commit();

                $success = 'تم إرسال المهمة بنجاح إلى ' . count($insertedTaskIds) . ' من عمال الإنتاج.';
            } catch (Exception $e) {
                $db->rollback();
                error_log('Manager production task creation error: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء إنشاء المهام. يرجى المحاولة مرة أخرى.';
            }
        }
    }
}

/**
 * إحصائيات سريعة للمهام التي أنشأها المدير
 */
$statsTemplate = [
    'total' => 0,
    'pending' => 0,
    'received' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];

$stats = $statsTemplate;
try {
    $counts = $db->query("
        SELECT status, COUNT(*) as total
        FROM tasks
        WHERE created_by = ?
        GROUP BY status
    ", [$currentUser['id']]);

    foreach ($counts as $row) {
        $statusKey = $row['status'] ?? '';
        if (isset($stats[$statusKey])) {
            $stats[$statusKey] = (int)$row['total'];
        }
        $stats['total'] += (int)$row['total'];
    }
} catch (Exception $e) {
    error_log('Manager task stats error: ' . $e->getMessage());
}

$recentTasks = [];
$statusStyles = [
    'pending' => ['class' => 'warning', 'label' => 'معلقة'],
    'received' => ['class' => 'info', 'label' => 'مستلمة'],
    'in_progress' => ['class' => 'primary', 'label' => 'قيد التنفيذ'],
    'completed' => ['class' => 'success', 'label' => 'مكتملة'],
    'cancelled' => ['class' => 'danger', 'label' => 'ملغاة']
];

$priorityStyles = [
    'low' => ['class' => 'secondary', 'label' => 'منخفضة'],
    'normal' => ['class' => 'info', 'label' => 'عادية'],
    'high' => ['class' => 'warning', 'label' => 'مرتفعة'],
    'urgent' => ['class' => 'danger', 'label' => 'عاجلة']
];

try {
    $recentTasks = $db->query("
        SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at, t.product_id,
               t.quantity, t.notes, u.full_name AS assigned_name, p.name AS product_name
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        LEFT JOIN products p ON t.product_id = p.id
        WHERE t.created_by = ?
        ORDER BY t.created_at DESC
        LIMIT 10
    ", [$currentUser['id']]);
} catch (Exception $e) {
    error_log('Manager recent tasks error: ' . $e->getMessage());
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-list-task me-2"></i>إرسال مهام لقسم الإنتاج</h2>
            <p class="text-muted mb-0">قم بإنشاء مهام موجهة لعمال الإنتاج مع تتبّع الحالة في صفحة المهام الخاصة بهم.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-2 col-sm-6">
            <div class="card border-primary h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1">إجمالي المهام</div>
                    <div class="fs-4 text-primary fw-semibold"><?php echo $stats['total']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-warning h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1">معلقة</div>
                    <div class="fs-4 text-warning fw-semibold"><?php echo $stats['pending']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-info h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1">مستلمة</div>
                    <div class="fs-4 text-info fw-semibold"><?php echo $stats['received']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-info h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1">قيد التنفيذ</div>
                    <div class="fs-4 text-info fw-semibold"><?php echo $stats['in_progress']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-success h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1">مكتملة</div>
                    <div class="fs-4 text-success fw-semibold"><?php echo $stats['completed']; ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-danger h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1">ملغاة</div>
                    <div class="fs-4 text-danger fw-semibold"><?php echo $stats['cancelled']; ?></div>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#createTaskFormCollapse" aria-expanded="false" aria-controls="createTaskFormCollapse">
        <i class="bi bi-plus-circle me-1"></i>إنشاء مهمة جديدة
    </button>

    <div class="collapse" id="createTaskFormCollapse">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>إنشاء مهمة جديدة</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="action" value="create_production_task">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">نوع المهمة</label>
                            <select class="form-select" name="task_type" id="taskTypeSelect" required>
                                <option value="general">مهمة عامة</option>
                                <option value="production">إنتاج منتج</option>
                                <option value="quality">مهمة جودة</option>
                                <option value="maintenance">صيانة</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="low">منخفضة</option>
                                <option value="normal" selected>عادية</option>
                                <option value="high">مرتفعة</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">تاريخ الاستحقاق</label>
                            <input type="date" class="form-control" name="due_date" value="">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">اختر العمال المستهدفين</label>
                            <select class="form-select" name="assigned_to[]" multiple required size="6">
                                <?php foreach ($productionUsers as $worker): ?>
                                    <option value="<?php echo (int)$worker['id']; ?>"><?php echo htmlspecialchars($worker['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">يمكن تحديد أكثر من عامل باستخدام زر CTRL أو SHIFT.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">عنوان المهمة</label>
                            <input type="text" class="form-control" name="title" placeholder="مثال: تنظيف خط الإنتاج">
                            <div class="form-text">يمكنك ترك العنوان فارغاً وسيتم توليد عنوان افتراضي للمهمة الإنتاجية.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">وصف وتفاصيل المهمة</label>
                            <textarea class="form-control" name="details" rows="4" placeholder="أدخل التفاصيل والتعليمات اللازمة للعمال."></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-4 gap-2">
                        <button type="reset" class="btn btn-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>إعادة تعيين</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send-check me-1"></i>إرسال المهمة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>آخر المهام التي تم إرسالها</h5>
            <span class="text-muted small">آخر 10 مهام</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>العنوان</th>
                            <th>الموظف</th>
                            <th>الحالة</th>
                            <th>الأولوية</th>
                            <th>تاريخ الاستحقاق</th>
                            <th>تاريخ الإنشاء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTasks)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">لم تقم بإنشاء مهام بعد.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTasks as $task): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                        <?php if (!empty($task['product_name'])): ?>
                                            <div class="text-muted small">المنتج: <?php echo htmlspecialchars($task['product_name']); ?></div>
                                        <?php endif; ?>
                                        <?php if ((float)($task['quantity'] ?? 0) > 0): ?>
                                            <div class="text-muted small">الكمية: <?php echo number_format((float)$task['quantity'], 2); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($task['assigned_name'] ?? 'غير محدد'); ?></td>
                                    <td>
                                        <?php
                                        $statusKey = $task['status'] ?? '';
                                        $statusMeta = $statusStyles[$statusKey] ?? ['class' => 'secondary', 'label' => 'غير معروفة'];
                                        ?>
                                        <span class="badge bg-<?php echo htmlspecialchars($statusMeta['class']); ?>">
                                            <?php echo htmlspecialchars($statusMeta['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $priorityKey = $task['priority'] ?? '';
                                        $priorityMeta = $priorityStyles[$priorityKey] ?? ['class' => 'secondary', 'label' => 'غير محدد'];
                                        ?>
                                        <span class="badge bg-<?php echo htmlspecialchars($priorityMeta['class']); ?>">
                                            <?php echo htmlspecialchars($priorityMeta['label']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $task['due_date'] ? htmlspecialchars($task['due_date']) : '<span class="text-muted">غير محدد</span>'; ?></td>
                                    <td><?php echo htmlspecialchars($task['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const taskTypeSelect = document.getElementById('taskTypeSelect');
    const titleInput = document.querySelector('input[name="title"]');

    function updateTitlePlaceholder() {
        if (!titleInput) {
            return;
        }
        const isProduction = taskTypeSelect && taskTypeSelect.value === 'production';
        titleInput.placeholder = isProduction
            ? 'يمكنك ترك العنوان فارغاً وسيتم توليد عنوان افتراضي للمهمة الإنتاجية.'
            : 'مثال: تنظيف خط الإنتاج';
    }

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', updateTitlePlaceholder);
    }
    updateTitlePlaceholder();
});
</script>

