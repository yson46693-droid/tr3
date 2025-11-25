<?php
/**
 * صفحة إرسال المهام لقسم الإنتاج
 */

// تعيين ترميز UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

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
require_once __DIR__ . '/../../includes/table_styles.php';

if (!function_exists('getTasksRetentionLimit')) {
    function getTasksRetentionLimit(): int {
        if (defined('TASKS_RETENTION_MAX_ROWS')) {
            $value = (int) TASKS_RETENTION_MAX_ROWS;
            if ($value > 0) {
                return $value;
            }
        }
        return 100;
    }
}

if (!function_exists('enforceTasksRetentionLimit')) {
    function enforceTasksRetentionLimit($dbInstance = null, int $maxRows = 100) {
        $maxRows = (int) $maxRows;
        if ($maxRows < 1) {
            $maxRows = 100;
        }

        try {
            if ($dbInstance === null) {
                $dbInstance = db();
            }

            if (!$dbInstance) {
                return false;
            }

            $totalRow = $dbInstance->queryOne("SELECT COUNT(*) AS total FROM tasks");
            $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

            if ($total <= $maxRows) {
                return true;
            }

            $toDelete = $total - $maxRows;
            $batchSize = 100;

            while ($toDelete > 0) {
                $currentBatch = min($batchSize, $toDelete);

                $oldest = $dbInstance->query(
                    "SELECT id FROM tasks ORDER BY created_at ASC, id ASC LIMIT ?",
                    [$currentBatch]
                );

                if (empty($oldest)) {
                    break;
                }

                $ids = array_map('intval', array_column($oldest, 'id'));
                $ids = array_filter($ids, static function ($id) {
                    return $id > 0;
                });

                if (empty($ids)) {
                    break;
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $dbInstance->execute(
                    "DELETE FROM tasks WHERE id IN ($placeholders)",
                    $ids
                );

                $deleted = count($ids);
                $toDelete -= $deleted;

                if ($deleted < $currentBatch) {
                    break;
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log('Tasks retention enforce error: ' . $e->getMessage());
            return false;
        }
    }
}

requireRole('manager');

$db = db();
$currentUser = getCurrentUser();
$error = '';
$success = '';
$tasksRetentionLimit = getTasksRetentionLimit();

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
 * تحميل بيانات المستخدمين
 */
$productionUsers = [];

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
        $productName = trim($_POST['product_name'] ?? '');

        $productQuantityInput = isset($_POST['product_quantity']) ? trim((string)$_POST['product_quantity']) : '';
        $productQuantity = null;
        if ($productQuantityInput !== '') {
            $normalizedQuantity = str_replace(',', '.', $productQuantityInput);
            if (!is_numeric($normalizedQuantity)) {
                $error = 'يرجى إدخال كمية صحيحة أو ترك الحقل فارغاً.';
            } else {
                $productQuantity = (float)$normalizedQuantity;
                if ($productQuantity < 0) {
                    $error = 'لا يمكن أن تكون الكمية سالبة.';
                }
            }
        }
        if ($productQuantity !== null && $productQuantity <= 0) {
            $productQuantity = null;
        }

        if (!is_array($assignees)) {
            $assignees = [$assignees];
        }

        $assignees = array_unique(array_filter(array_map('intval', $assignees)));
        $allowedAssignees = array_map(function ($user) {
            return (int)($user['id'] ?? 0);
        }, $productionUsers);
        $assignees = array_values(array_intersect($assignees, $allowedAssignees));

        if ($error !== '') {
            // تم ضبط رسالة الخطأ أعلاه (مثل التحقق من الكمية)
        } elseif (empty($assignees)) {
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

                // الحصول على أسماء العمال المختارين
                $assigneeNames = [];
                foreach ($assignees as $assignedId) {
                    foreach ($productionUsers as $user) {
                        if ((int)$user['id'] === $assignedId) {
                            $assigneeNames[] = $user['full_name'];
                            break;
                        }
                    }
                }

                // إنشاء مهمة واحدة فقط مع حفظ جميع العمال
                $columns = ['title', 'description', 'created_by', 'priority', 'status', 'related_type'];
                $values = [$title, $details ?: null, $currentUser['id'], $priority, 'pending', $relatedTypeValue];
                $placeholders = ['?', '?', '?', '?', '?', '?'];

                // وضع أول عامل في assigned_to للتوافق مع الكود الحالي
                $firstAssignee = !empty($assignees) ? (int)$assignees[0] : 0;
                if ($firstAssignee > 0) {
                    $columns[] = 'assigned_to';
                    $values[] = $firstAssignee;
                    $placeholders[] = '?';
                }

                if ($dueDate) {
                    $columns[] = 'due_date';
                    $values[] = $dueDate;
                    $placeholders[] = '?';
                }

                // بناء notes مع معلومات المنتج والعمال
                $notesParts = [];
                if ($details) {
                    $notesParts[] = $details;
                }
                
                if ($productName !== '') {
                    $productInfo = 'المنتج: ' . $productName;
                    if ($productQuantity !== null) {
                        $productInfo .= ' - الكمية: ' . $productQuantity;
                    }
                    $notesParts[] = $productInfo;
                }
                
                // حفظ قائمة العمال في notes
                if (count($assignees) > 1) {
                    $assigneesInfo = 'العمال المخصصون: ' . implode(', ', $assigneeNames);
                    $assigneesInfo .= "\n[ASSIGNED_WORKERS_IDS]:" . implode(',', $assignees);
                    $notesParts[] = $assigneesInfo;
                } elseif (count($assignees) === 1) {
                    $assigneesInfo = 'العامل المخصص: ' . ($assigneeNames[0] ?? '');
                    $assigneesInfo .= "\n[ASSIGNED_WORKERS_IDS]:" . $assignees[0];
                    $notesParts[] = $assigneesInfo;
                }
                
                $notesValue = !empty($notesParts) ? implode("\n\n", $notesParts) : null;
                if ($notesValue) {
                    $columns[] = 'notes';
                    $values[] = $notesValue;
                    $placeholders[] = '?';
                }

                if ($productQuantity !== null) {
                    $columns[] = 'quantity';
                    $values[] = $productQuantity;
                    $placeholders[] = '?';
                }

                $sql = "INSERT INTO tasks (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $result = $db->execute($sql, $values);
                $taskId = $result['insert_id'] ?? 0;

                if ($taskId <= 0) {
                    throw new Exception('تعذر إنشاء المهمة.');
                }

                logAudit(
                    $currentUser['id'],
                    'create_production_task',
                    'tasks',
                    $taskId,
                    null,
                    [
                        'task_type' => $taskType,
                        'assigned_to' => $assignees,
                        'assigned_count' => count($assignees),
                        'priority' => $priority,
                        'due_date' => $dueDate,
                        'product_name' => $productName ?: null,
                        'quantity' => $productQuantity
                    ]
                );

                // إرسال إشعارات لجميع العمال المختارين
                $notificationTitle = 'مهمة جديدة من الإدارة';
                $notificationMessage = $title;
                if (count($assignees) > 1) {
                    $notificationMessage .= ' (مشتركة مع ' . (count($assignees) - 1) . ' عامل آخر)';
                }

                foreach ($assignees as $assignedId) {
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

                enforceTasksRetentionLimit($db, $tasksRetentionLimit);

                $db->commit();

                $success = 'تم إرسال المهمة بنجاح إلى ' . count($assignees) . ' من عمال الإنتاج.';
            } catch (Exception $e) {
                $db->rollback();
                error_log('Manager production task creation error: ' . $e->getMessage());
                $error = 'حدث خطأ أثناء إنشاء المهام. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'cancel_task') {
        $taskId = intval($_POST['task_id'] ?? 0);

        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح.';
        } else {
            try {
                $db->beginTransaction();

                $task = $db->queryOne(
                    "SELECT id, title, status FROM tasks WHERE id = ? AND created_by = ? LIMIT 1",
                    [$taskId, $currentUser['id']]
                );

                if (!$task) {
                    throw new Exception('المهمة غير موجودة أو ليست من إنشائك.');
                }

                if ($task['status'] === 'cancelled') {
                    throw new Exception('المهمة ملغاة مسبقاً.');
                }

                $db->execute(
                    "UPDATE tasks SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
                    [$taskId]
                );

                // تعليم الإشعارات القديمة كمقروءة
                $db->execute(
                    "UPDATE notifications SET `read` = 1 WHERE message = ? AND type IN ('info','success','warning')",
                    [$task['title']]
                );

                // إرسال إشعار عاجل لجميع عمال الإنتاج
                $workers = $db->query("SELECT id FROM users WHERE status = 'active' AND role = 'production'");
                $alertTitle = 'تنبيه هام: تم إلغاء مهمة';
                $alertMessage = 'تم إلغاء المهمة "' . $task['title'] . '" من قبل الإدارة. يرجى تجاهلها.';
                $alertLink = getRelativeUrl('production.php?page=tasks');

                foreach ($workers as $worker) {
                    try {
                        createNotification(
                            (int)$worker['id'],
                            $alertTitle,
                            $alertMessage,
                            'error',
                            $alertLink
                        );
                    } catch (Exception $notifyError) {
                        error_log('Cancel task notification error: ' . $notifyError->getMessage());
                    }
                }

                logAudit(
                    $currentUser['id'],
                    'cancel_task',
                    'tasks',
                    $taskId,
                    null,
                    ['title' => $task['title']]
                );

                $db->commit();
                $success = 'تم إلغاء المهمة وإخطار عمال الإنتاج بنجاح.';
            } catch (Exception $cancelError) {
                $db->rollBack();
                $error = 'تعذر إلغاء المهمة: ' . $cancelError->getMessage();
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
        SELECT t.id, t.title, t.status, t.priority, t.due_date, t.created_at,
               t.quantity, t.notes, u.full_name AS assigned_name, t.assigned_to
        FROM tasks t
        LEFT JOIN users u ON t.assigned_to = u.id
        WHERE t.created_by = ?
        ORDER BY t.created_at DESC
        LIMIT 10
    ", [$currentUser['id']]);
    
    // استخراج جميع العمال من notes لكل مهمة
    foreach ($recentTasks as &$task) {
        $notes = $task['notes'] ?? '';
        $allWorkers = [];
        
        // محاولة استخراج IDs من notes
        if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $notes, $matches)) {
            $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
            if (!empty($workerIds)) {
                $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                $workers = $db->query(
                    "SELECT id, full_name FROM users WHERE id IN ($placeholders) ORDER BY full_name",
                    $workerIds
                );
                foreach ($workers as $worker) {
                    $allWorkers[] = $worker['full_name'];
                }
            }
        }
        
        // إذا لم نجد عمال من notes، استخدم assigned_to
        if (empty($allWorkers) && !empty($task['assigned_name'])) {
            $allWorkers[] = $task['assigned_name'];
        }
        
        $task['all_workers'] = $allWorkers;
        $task['workers_count'] = count($allWorkers);
    }
    unset($task);
} catch (Exception $e) {
    error_log('Manager recent tasks error: ' . $e->getMessage());
}

?>

<style>
    html #pageLoader,
    body #pageLoader,
    #pageLoader {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

</style>
<script>
(function () {
    function removePageLoader() {
        const loader = document.getElementById('pageLoader');
        if (!loader) {
            return;
        }
        loader.classList.add('hidden');
        loader.setAttribute('aria-hidden', 'true');
        loader.style.display = 'none';
        loader.style.visibility = 'hidden';
        loader.style.opacity = '0';
        loader.style.pointerEvents = 'none';
    }

    removePageLoader();
    document.addEventListener('readystatechange', removePageLoader);
    document.addEventListener('DOMContentLoaded', removePageLoader);
    window.addEventListener('load', removePageLoader);
    window.addEventListener('pageshow', removePageLoader);
})();
</script>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="bi bi-list-task me-2"></i>إرسال مهام لقسم الإنتاج</h2>
            <p class="text-muted mb-0">قم بإنشاء مهام موجهة لعمال الإنتاج مع تتبّع الحالة في صفحة المهام الخاصة بهم.</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true" role="alert">
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
                        <div class="col-md-6" id="productFieldWrapper">
                            <label class="form-label">المنتج (اختياري)</label>
                            <input type="text" class="form-control" name="product_name" id="productNameInput" placeholder="أدخل اسم المنتج">
                            <div class="form-text">اختياري: أدخل اسم المنتج المرتبط بالمهمة.</div>
                        </div>
                        <div class="col-md-6" id="quantityFieldWrapper">
                            <label class="form-label">الكمية (اختياري)</label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="product_quantity" id="productQuantityInput" step="0.01" min="0" placeholder="مثال: 120">
                                <span class="input-group-text">وحدة</span>
                            </div>
                            <div class="form-text">اختياري: أدخل الكمية المرتبطة بالمهمة.</div>
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
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>العنوان</th>
                            <th>الموظف</th>
                            <th>الحالة</th>
                            <th>الأولوية</th>
                            <th>تاريخ الاستحقاق</th>
                            <th>تاريخ الإنشاء</th>
                            <th>إجراءات</th>
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
                                        <?php 
                                        // استخراج معلومات المنتج من notes إذا كانت موجودة
                                        $notes = $task['notes'] ?? '';
                                        if ($notes && preg_match('/المنتج:\s*(.+?)(?:\s*-\s*الكمية:|$)/i', $notes, $matches)) {
                                            $extractedProductName = trim($matches[1] ?? '');
                                            if ($extractedProductName) {
                                                echo '<div class="text-muted small">المنتج: ' . htmlspecialchars($extractedProductName) . '</div>';
                                            }
                                        }
                                        ?>
                                        <?php if ((float)($task['quantity'] ?? 0) > 0): ?>
                                            <div class="text-muted small">الكمية: <?php echo number_format((float)$task['quantity'], 2); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (!empty($task['all_workers'])) {
                                            $workersList = $task['all_workers'];
                                            if (count($workersList) > 1) {
                                                echo '<span class="badge bg-info me-1">' . count($workersList) . ' عمال</span><br>';
                                                echo '<small class="text-muted">' . htmlspecialchars(implode(', ', $workersList)) . '</small>';
                                            } else {
                                                echo htmlspecialchars($workersList[0]);
                                            }
                                        } else {
                                            echo htmlspecialchars($task['assigned_name'] ?? 'غير محدد');
                                        }
                                        ?>
                                    </td>
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
                                    <td>
                                        <?php if (in_array($task['status'], ['completed', 'cancelled'], true)): ?>
                                            <span class="text-muted small">لا يوجد إجراء</span>
                                        <?php else: ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('هل أنت متأكد من إلغاء هذه المهمة؟');">
                                                <input type="hidden" name="action" value="cancel_task">
                                                <input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="bi bi-x-circle me-1"></i>إلغاء المهمة
                                                </button>
                                            </form>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const taskTypeSelect = document.getElementById('taskTypeSelect');
    const titleInput = document.querySelector('input[name="title"]');
    const productWrapper = document.getElementById('productFieldWrapper');
    const quantityWrapper = document.getElementById('quantityFieldWrapper');
    const productNameInput = document.getElementById('productNameInput');
    const quantityInput = document.getElementById('productQuantityInput');

    function updateTaskTypeUI() {
        if (!titleInput) {
            // continue to toggle other fields even إن لم يوجد العنوان
        }
        const isProduction = taskTypeSelect && taskTypeSelect.value === 'production';
        if (titleInput) {
            titleInput.placeholder = isProduction
                ? 'يمكنك ترك العنوان فارغاً وسيتم توليد عنوان افتراضي للمهمة الإنتاجية.'
                : 'مثال: تنظيف خط الإنتاج';
        }
    }

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', updateTaskTypeUI);
    }
    updateTaskTypeUI();
});
</script>

