<?php
/**
 * صفحة إدارة المهام (نسخة مبسطة محسّنة)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();

$errorMessages = [];
$successMessages = [];

$isManager = ($currentUser['role'] ?? '') === 'manager';
$isProduction = ($currentUser['role'] ?? '') === 'production';

if (!function_exists('tasksSafeString')) {
    function tasksSafeString($value)
    {
        if ($value === null || (!is_scalar($value) && $value !== '')) {
            return '';
        }

        $value = (string) $value;

        if ($value === '') {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            static $supportedSources = null;

            if ($supportedSources === null) {
                $preferred = ['UTF-8', 'ISO-8859-1', 'Windows-1256', 'Windows-1252'];
                $available = array_map('strtolower', mb_list_encodings());
                $supportedSources = [];

                foreach ($preferred as $encoding) {
                    if (in_array(strtolower($encoding), $available, true)) {
                        $supportedSources[] = $encoding;
                    }
                }

                if (empty($supportedSources)) {
                    $supportedSources[] = 'UTF-8';
                }
            }

            $converted = @mb_convert_encoding($value, 'UTF-8', $supportedSources);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return trim($value);
    }
}

if (!function_exists('tasksSafeJsonEncode')) {
    function tasksSafeJsonEncode($data): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

        $json = json_encode($data, $options);
        if ($json === false) {
            $sanitized = tasksSanitizeForJson($data);
            $json = json_encode($sanitized, $options);
        }

        return $json !== false ? $json : '[]';
    }
}

if (!function_exists('tasksSanitizeForJson')) {
    function tasksSanitizeForJson($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = tasksSanitizeForJson($item);
            }
            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->$key = tasksSanitizeForJson($item);
            }
            return $value;
        }

        if (is_string($value) || is_numeric($value)) {
            return tasksSafeString($value);
        }

        return $value;
    }
}

if (!function_exists('getTasksRetentionLimit')) {
    function getTasksRetentionLimit(): int
    {
        if (defined('TASKS_RETENTION_MAX_ROWS')) {
            $limit = (int) TASKS_RETENTION_MAX_ROWS;
            if ($limit > 0) {
                return $limit;
            }
        }

        return 100;
    }
}

if (!function_exists('enforceTasksRetentionLimit')) {
    function enforceTasksRetentionLimit($dbInstance = null, int $maxRows = 100): bool
    {
        $maxRows = max(1, (int) $maxRows);

        try {
            if ($dbInstance === null) {
                $dbInstance = db();
            }

            if (!$dbInstance) {
                return false;
            }

            $totalRow = $dbInstance->queryOne('SELECT COUNT(*) AS total FROM tasks');
            $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

            if ($total <= $maxRows) {
                return true;
            }

            $toDelete = $total - $maxRows;
            $ids = $dbInstance->query(
                'SELECT id FROM tasks ORDER BY created_at ASC, id ASC LIMIT ?',
                [max(1, $toDelete)]
            );

            if (empty($ids)) {
                return true;
            }

            $idValues = array_map(static function ($row) {
                return (int) $row['id'];
            }, $ids);

            $placeholders = implode(',', array_fill(0, count($idValues), '?'));
            $dbInstance->execute("DELETE FROM tasks WHERE id IN ($placeholders)", $idValues);

            return true;
        } catch (Throwable $e) {
            error_log('Tasks retention error: ' . $e->getMessage());
            return false;
        }
    }
}

function tasksAddMessage(array &$bag, string $message): void
{
    $trimmed = tasksSafeString($message);
    if ($trimmed !== '') {
        $bag[] = $trimmed;
    }
}

function tasksHandleAction(string $action, array $input, array $context): array
{
    $db = $context['db'];
    $currentUser = $context['user'];
    $isManager = (bool) ($context['is_manager'] ?? false);
    $isProduction = (bool) ($context['is_production'] ?? false);
    $retentionLimit = (int) $context['retention_limit'];

    $result = ['error' => null, 'success' => null];

    try {
        switch ($action) {
            case 'add_task':
                if (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بإنشاء مهام');
                }

                $title = tasksSafeString($input['title'] ?? '');
                $description = tasksSafeString($input['description'] ?? '');
                $assignedTo = isset($input['assigned_to']) ? (int) $input['assigned_to'] : 0;
                $priority = in_array(($input['priority'] ?? 'normal'), ['low', 'normal', 'high', 'urgent'], true)
                    ? $input['priority']
                    : 'normal';
                $dueDate = tasksSafeString($input['due_date'] ?? '');
                $relatedType = tasksSafeString($input['related_type'] ?? '');
                $relatedId = isset($input['related_id']) ? (int) $input['related_id'] : 0;
                $productId = isset($input['product_id']) ? (int) $input['product_id'] : 0;
                $quantity = isset($input['quantity']) ? (float) $input['quantity'] : 0.0;
                $taskType = $input['task_type'] ?? 'general';
                $notes = tasksSafeString($input['notes'] ?? '');

                if ($title === '' && $taskType !== 'production') {
                    throw new RuntimeException('يجب إدخال عنوان المهمة');
                }

                if ($taskType === 'production') {
                    if ($productId <= 0) {
                        throw new RuntimeException('يجب اختيار منتج لمهمة الإنتاج');
                    }

                    if ($quantity <= 0) {
                        throw new RuntimeException('يجب إدخال كمية صحيحة لمهمة الإنتاج');
                    }

                    $product = $db->queryOne('SELECT name FROM products WHERE id = ?', [$productId]);
                    if ($product) {
                        $title = 'إنتاج ' . tasksSafeString($product['name']) . ' - ' . number_format($quantity, 2) . ' قطعة';
                    }
                }

                if ($title === '') {
                    throw new RuntimeException('يجب إدخال عنوان المهمة');
                }

                $columns = ['title', 'created_by', 'priority', 'status'];
                $values = [$title, (int) $currentUser['id'], $priority, 'pending'];
                $placeholders = ['?', '?', '?', '?'];

                if ($description !== '') {
                    $columns[] = 'description';
                    $values[] = $description;
                    $placeholders[] = '?';
                }

                if ($assignedTo > 0) {
                    $columns[] = 'assigned_to';
                    $values[] = $assignedTo;
                    $placeholders[] = '?';
                }

                if ($dueDate !== '') {
                    $columns[] = 'due_date';
                    $values[] = $dueDate;
                    $placeholders[] = '?';
                }

                if ($relatedType !== '' && $relatedId > 0) {
                    $columns[] = 'related_type';
                    $columns[] = 'related_id';
                    $values[] = $relatedType;
                    $values[] = $relatedId;
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                }

                if ($productId > 0) {
                    $columns[] = 'product_id';
                    $values[] = $productId;
                    $placeholders[] = '?';
                }

                if ($quantity > 0) {
                    $columns[] = 'quantity';
                    $values[] = $quantity;
                    $placeholders[] = '?';
                }

                if ($notes !== '') {
                    $columns[] = 'notes';
                    $values[] = $notes;
                    $placeholders[] = '?';
                }

                $sql = 'INSERT INTO tasks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $insertResult = $db->execute($sql, $values);
                $insertId = $insertResult['insert_id'] ?? 0;

                enforceTasksRetentionLimit($db, $retentionLimit);
                logAudit($currentUser['id'], 'add_task', 'tasks', $insertId, null, ['title' => $title, 'type' => $taskType]);

                $result['success'] = 'تم إضافة المهمة بنجاح';
                break;

            case 'receive_task':
            case 'start_task':
            case 'complete_task':
                if (!$isProduction) {
                    throw new RuntimeException('غير مصرح لك بتنفيذ هذا الإجراء');
                }

                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                if ($taskId <= 0) {
                    throw new RuntimeException('معرف المهمة غير صحيح');
                }

                $task = $db->queryOne('SELECT assigned_to, status, title, created_by, notes FROM tasks WHERE id = ?', [$taskId]);
                if (!$task) {
                    throw new RuntimeException('المهمة غير موجودة');
                }

                // التحقق من أن المهمة مخصصة لعامل إنتاج
                $isAssignedToProduction = false;
                
                // التحقق من assigned_to
                if (!empty($task['assigned_to'])) {
                    $assignedUser = $db->queryOne('SELECT role FROM users WHERE id = ?', [(int) $task['assigned_to']]);
                    if ($assignedUser && $assignedUser['role'] === 'production') {
                        $isAssignedToProduction = true;
                    }
                }
                
                // التحقق من notes للعثور على جميع العمال المخصصين
                if (!$isAssignedToProduction && !empty($task['notes'])) {
                    if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $task['notes'], $matches)) {
                        $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
                        if (in_array((int)$currentUser['id'], $workerIds, true)) {
                            $isAssignedToProduction = true;
                        }
                    }
                }
                
                if (!$isAssignedToProduction) {
                    throw new RuntimeException('هذه المهمة غير مخصصة لعامل إنتاج');
                }

                $statusMap = [
                    'receive_task' => ['status' => 'received', 'column' => 'received_at'],
                    'start_task' => ['status' => 'in_progress', 'column' => 'started_at'],
                    'complete_task' => ['status' => 'completed', 'column' => 'completed_at'],
                ];

                $update = $statusMap[$action];
                $db->execute(
                    "UPDATE tasks SET status = ?, {$update['column']} = NOW() WHERE id = ?",
                    [$update['status'], $taskId]
                );

                logAudit($currentUser['id'], $action, 'tasks', $taskId, null, ['status' => $update['status']]);

                if ($action === 'complete_task') {
                    try {
                        $taskTitle = tasksSafeString($task['title'] ?? ('مهمة #' . $taskId));
                        $link = getRelativeUrl('production.php?page=tasks');
                        createNotification(
                            $currentUser['id'],
                            'تم إكمال المهمة',
                            'تم تسجيل المهمة "' . $taskTitle . '" كمكتملة.',
                            'success',
                            $link
                        );

                        if (!empty($task['created_by']) && (int) $task['created_by'] !== (int) $currentUser['id']) {
                            createNotification(
                                (int) $task['created_by'],
                                'تم إكمال مهمة الإنتاج',
                                ($currentUser['full_name'] ?? $currentUser['username'] ?? 'عامل الإنتاج') .
                                    ' أكمل المهمة "' . $taskTitle . '".',
                                'success',
                                getRelativeUrl('manager.php?page=tasks')
                            );
                        }
                    } catch (Throwable $notificationError) {
                        error_log('Task completion notification error: ' . $notificationError->getMessage());
                    }
                }

                $result['success'] = 'تم تحديث حالة المهمة بنجاح';
                break;

            case 'change_status':
                if (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بتغيير حالة المهمة');
                }

                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                $status = $input['status'] ?? 'pending';
                $validStatuses = ['pending', 'received', 'in_progress', 'completed', 'cancelled'];

                if ($taskId <= 0 || !in_array($status, $validStatuses, true)) {
                    throw new RuntimeException('بيانات غير صحيحة لتحديث المهمة');
                }

                $setParts = ['status = ?'];
                $values = [$status];

                $setParts[] = $status === 'completed' ? 'completed_at = NOW()' : 'completed_at = NULL';
                $setParts[] = $status === 'received' ? 'received_at = NOW()' : 'received_at = NULL';
                $setParts[] = $status === 'in_progress' ? 'started_at = NOW()' : 'started_at = NULL';

                $values[] = $taskId;

                $db->execute('UPDATE tasks SET ' . implode(', ', $setParts) . ' WHERE id = ?', $values);
                logAudit($currentUser['id'], 'change_task_status', 'tasks', $taskId, null, ['status' => $status]);

                $result['success'] = 'تم تحديث حالة المهمة بنجاح';
                break;

            case 'delete_task':
                if (!$isManager) {
                    throw new RuntimeException('غير مصرح لك بحذف المهام');
                }

                $taskId = isset($input['task_id']) ? (int) $input['task_id'] : 0;
                if ($taskId <= 0) {
                    throw new RuntimeException('معرف المهمة غير صحيح');
                }

                $db->execute('DELETE FROM tasks WHERE id = ?', [$taskId]);
                logAudit($currentUser['id'], 'delete_task', 'tasks', $taskId, null, null);

                $result['success'] = 'تم حذف المهمة بنجاح';
                break;

            default:
                throw new RuntimeException('إجراء غير معروف');
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = tasksSafeString($_POST['action'] ?? '');

    if ($action !== '') {
        $context = [
            'db' => $db,
            'user' => $currentUser,
            'is_manager' => $isManager,
            'is_production' => $isProduction,
            'retention_limit' => getTasksRetentionLimit(),
        ];

        $result = tasksHandleAction($action, $_POST, $context);
        if ($result['error']) {
            tasksAddMessage($errorMessages, $result['error']);
        } elseif ($result['success']) {
            tasksAddMessage($successMessages, $result['success']);
        }
    }
}

$pageNum = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$search = tasksSafeString($_GET['search'] ?? '');
$statusFilter = tasksSafeString($_GET['status'] ?? '');
$priorityFilter = tasksSafeString($_GET['priority'] ?? '');
$assignedFilter = isset($_GET['assigned']) ? (int) $_GET['assigned'] : 0;

$whereConditions = [];
$params = [];

if ($search !== '') {
    $whereConditions[] = '(t.title LIKE ? OR t.description LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($statusFilter !== '') {
    $whereConditions[] = 't.status = ?';
    $params[] = $statusFilter;
} else {
    $whereConditions[] = "t.status != 'cancelled'";
}

if ($priorityFilter !== '' && in_array($priorityFilter, ['low', 'normal', 'high', 'urgent'], true)) {
    $whereConditions[] = 't.priority = ?';
    $params[] = $priorityFilter;
}

if ($assignedFilter > 0) {
    $whereConditions[] = 't.assigned_to = ?';
    $params[] = $assignedFilter;
}

// السماح لجميع عمال الإنتاج برؤية جميع المهام المخصصة لأي عامل إنتاج
// لا حاجة للفلترة - جميع عمال الإنتاج يرون جميع المهام

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$totalRow = $db->queryOne('SELECT COUNT(*) AS total FROM tasks t ' . $whereClause, $params);
$totalTasks = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;
$totalPages = max(1, (int) ceil($totalTasks / $perPage));

$taskSql = "SELECT t.*, 
    uAssign.full_name AS assigned_to_name,
    uCreate.full_name AS created_by_name,
    p.name AS product_name
FROM tasks t
LEFT JOIN users uAssign ON t.assigned_to = uAssign.id
LEFT JOIN users uCreate ON t.created_by = uCreate.id
LEFT JOIN products p ON t.product_id = p.id
$whereClause
ORDER BY 
    CASE t.priority
        WHEN 'urgent' THEN 1
        WHEN 'high' THEN 2
        WHEN 'normal' THEN 3
        WHEN 'low' THEN 4
        ELSE 5
    END,
    COALESCE(t.due_date, '9999-12-31') ASC,
    t.created_at DESC
LIMIT ? OFFSET ?";

$queryParams = array_merge($params, [$perPage, $offset]);
$tasks = $db->query($taskSql, $queryParams);

// استخراج جميع العمال من notes لكل مهمة
foreach ($tasks as &$task) {
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
    if (empty($allWorkers) && !empty($task['assigned_to_name'])) {
        $allWorkers[] = $task['assigned_to_name'];
    }
    
    $task['all_workers'] = $allWorkers;
    $task['workers_count'] = count($allWorkers);
}
unset($task);

$users = $db->query("SELECT id, full_name FROM users WHERE status = 'active' AND role = 'production' ORDER BY full_name");
$products = $db->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");

$statsBaseConditions = [];
$statsBaseParams = [];

// إزالة الفلترة - السماح لجميع عمال الإنتاج برؤية إحصائيات جميع المهام
// if ($isProduction) {
//     $statsBaseConditions[] = 'assigned_to = ?';
//     $statsBaseParams[] = (int) $currentUser['id'];
// }

$buildStatsQuery = function (?string $extraCondition = null, array $extraParams = []) use ($db, $statsBaseConditions, $statsBaseParams) {
    $conditions = $statsBaseConditions;
    if ($extraCondition) {
        $conditions[] = $extraCondition;
    }

    $params = array_merge($statsBaseParams, $extraParams);
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $row = $db->queryOne('SELECT COUNT(*) AS total FROM tasks ' . $where, $params);
    return isset($row['total']) ? (int) $row['total'] : 0;
};

$stats = [
    'total' => $buildStatsQuery(),
    'pending' => $buildStatsQuery("status = 'pending'"),
    'received' => $buildStatsQuery("status = 'received'"),
    'in_progress' => $buildStatsQuery("status = 'in_progress'"),
    'completed' => $buildStatsQuery("status = 'completed'"),
    'overdue' => $buildStatsQuery("status != 'completed' AND due_date < CURDATE()")
];

$tasksJson = tasksSafeJsonEncode($tasks);

function tasksHtml(string $value): string
{
    return htmlspecialchars(tasksSafeString($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>

<div class="container-fluid">
    <?php foreach ($errorMessages as $message): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo tasksHtml($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endforeach; ?>

    <?php foreach ($successMessages as $message): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo tasksHtml($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
        </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <h2 class="mb-0"><i class="bi bi-list-check me-2"></i>إدارة المهام</h2>
        <?php if ($isManager): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                <i class="bi bi-plus-circle me-2"></i>إضافة مهمة جديدة
            </button>
        <?php endif; ?>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card border-primary text-center h-100">
                <div class="card-body p-2">
                    <h5 class="text-primary mb-0"><?php echo $stats['total']; ?></h5>
                    <small class="text-muted">إجمالي المهام</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-warning text-center h-100">
                <div class="card-body p-2">
                    <h5 class="text-warning mb-0"><?php echo $stats['pending']; ?></h5>
                    <small class="text-muted">معلقة</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-info text-center h-100">
                <div class="card-body p-2">
                    <h5 class="text-info mb-0"><?php echo $stats['received']; ?></h5>
                    <small class="text-muted">مستلمة</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-info text-center h-100">
                <div class="card-body p-2">
                    <h5 class="text-info mb-0"><?php echo $stats['in_progress']; ?></h5>
                    <small class="text-muted">قيد التنفيذ</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-success text-center h-100">
                <div class="card-body p-2">
                    <h5 class="text-success mb-0"><?php echo $stats['completed']; ?></h5>
                    <small class="text-muted">مكتملة</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-danger text-center h-100">
                <div class="card-body p-2">
                    <h5 class="text-danger mb-0"><?php echo $stats['overdue']; ?></h5>
                    <small class="text-muted">متأخرة</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body p-3">
            <form method="GET" action="" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="tasks">
                <div class="col-md-3 col-sm-6">
                    <label class="form-label mb-1">بحث</label>
                    <input type="text" class="form-control form-control-sm" name="search" value="<?php echo tasksHtml($search); ?>" placeholder="عنوان أو وصف">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-1">الحالة</label>
                    <select class="form-select form-select-sm" name="status">
                        <option value="">الكل</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>معلقة</option>
                        <option value="received" <?php echo $statusFilter === 'received' ? 'selected' : ''; ?>>مستلمة</option>
                        <option value="in_progress" <?php echo $statusFilter === 'in_progress' ? 'selected' : ''; ?>>قيد التنفيذ</option>
                        <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>مكتملة</option>
                        <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>ملغاة</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-1">الأولوية</label>
                    <select class="form-select form-select-sm" name="priority">
                        <option value="">الكل</option>
                        <option value="urgent" <?php echo $priorityFilter === 'urgent' ? 'selected' : ''; ?>>عاجلة</option>
                        <option value="high" <?php echo $priorityFilter === 'high' ? 'selected' : ''; ?>>عالية</option>
                        <option value="normal" <?php echo $priorityFilter === 'normal' ? 'selected' : ''; ?>>عادية</option>
                        <option value="low" <?php echo $priorityFilter === 'low' ? 'selected' : ''; ?>>منخفضة</option>
                    </select>
                </div>
                <?php if ($isManager): ?>
                    <div class="col-md-2 col-sm-6">
                        <label class="form-label mb-1">المخصص إلى</label>
                        <select class="form-select form-select-sm" name="assigned">
                            <option value="0">الكل</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo (int) $user['id']; ?>" <?php echo $assignedFilter === (int) $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo tasksHtml($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-2 col-sm-6">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-search me-1"></i>بحث
                    </button>
                </div>
                <div class="col-md-1 col-sm-6">
                    <a href="?page=tasks" class="btn btn-secondary btn-sm w-100">
                        <i class="bi bi-x"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($tasks)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-5 text-muted"></i>
                    <p class="text-muted mt-3 mb-0">لا توجد مهام</p>
                </div>
            <?php else: ?>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--no-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>العنوان</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المخصص إلى</th>
                                <th>الأولوية</th>
                                <th>الحالة</th>
                                <th>تاريخ الاستحقاق</th>
                                <th>أنشئت بواسطة</th>
                                <th style="width: 180px;">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $index => $task): ?>
                                <?php
                                $rowNumber = ($pageNum - 1) * $perPage + $index + 1;
                                $priorityClass = [
                                    'urgent' => 'danger',
                                    'high' => 'warning',
                                    'normal' => 'info',
                                    'low' => 'secondary',
                                ][$task['priority']] ?? 'secondary';

                                $statusClass = [
                                    'pending' => 'warning',
                                    'received' => 'info',
                                    'in_progress' => 'primary',
                                    'completed' => 'success',
                                    'cancelled' => 'secondary',
                                ][$task['status']] ?? 'secondary';

                                $statusLabel = [
                                    'pending' => 'معلقة',
                                    'received' => 'مستلمة',
                                    'in_progress' => 'قيد التنفيذ',
                                    'completed' => 'مكتملة',
                                    'cancelled' => 'ملغاة'
                                ][$task['status']] ?? tasksSafeString($task['status']);

                                $priorityLabel = [
                                    'urgent' => 'عاجلة',
                                    'high' => 'عالية',
                                    'normal' => 'عادية',
                                    'low' => 'منخفضة'
                                ][$task['priority']] ?? tasksSafeString($task['priority']);

                                $overdue = !in_array($task['status'], ['completed', 'cancelled'], true)
                                    && !empty($task['due_date'])
                                    && strtotime((string) $task['due_date']) < time();
                                ?>
                                <tr class="<?php echo $overdue ? 'table-danger' : ''; ?>">
                                    <td><?php echo $rowNumber; ?></td>
                                    <td>
                                        <strong><?php echo tasksHtml($task['title'] ?? ''); ?></strong>
                                        <?php if ($overdue): ?>
                                            <span class="badge bg-danger ms-1">متأخرة</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo isset($task['product_name']) && $task['product_name'] !== null ? tasksHtml($task['product_name']) : '<span class="text-muted">-</span>'; ?></td>
                                    <td><?php echo isset($task['quantity']) && $task['quantity'] !== null ? number_format((float) $task['quantity'], 2) . ' قطعة' : '<span class="text-muted">-</span>'; ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($task['all_workers'])) {
                                            $workersList = $task['all_workers'];
                                            if (count($workersList) > 1) {
                                                echo '<span class="badge bg-info me-1">' . count($workersList) . ' عمال</span><br>';
                                                echo '<small class="text-muted">' . tasksHtml(implode(', ', $workersList)) . '</small>';
                                            } else {
                                                echo tasksHtml($workersList[0]);
                                            }
                                        } else {
                                            echo isset($task['assigned_to_name']) ? tasksHtml($task['assigned_to_name']) : '<span class="text-muted">غير محدد</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="badge bg-<?php echo $priorityClass; ?>"><?php echo tasksHtml($priorityLabel); ?></span></td>
                                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo tasksHtml($statusLabel); ?></span></td>
                                    <td>
                                        <?php if (!empty($task['due_date'])): ?>
                                            <?php echo tasksHtml(date('Y-m-d', strtotime((string) $task['due_date']))); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo isset($task['created_by_name']) ? tasksHtml($task['created_by_name']) : ''; ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($isProduction): ?>
                                                <?php 
                                                // التحقق من أن المهمة مخصصة لعامل إنتاج
                                                $taskAssignedTo = (int) ($task['assigned_to'] ?? 0);
                                                $assignedUserRole = null;
                                                $isTaskForProduction = false;
                                                
                                                // التحقق من assigned_to
                                                if ($taskAssignedTo > 0) {
                                                    $assignedUser = $db->queryOne('SELECT role FROM users WHERE id = ?', [$taskAssignedTo]);
                                                    $assignedUserRole = $assignedUser['role'] ?? null;
                                                    if ($assignedUserRole === 'production') {
                                                        $isTaskForProduction = true;
                                                    }
                                                }
                                                
                                                // التحقق من notes للعثور على جميع العمال المخصصين
                                                if (!$isTaskForProduction && !empty($task['notes'])) {
                                                    if (preg_match('/\[ASSIGNED_WORKERS_IDS\]:\s*([0-9,]+)/', $task['notes'], $matches)) {
                                                        $workerIds = array_filter(array_map('intval', explode(',', $matches[1])));
                                                        if (!empty($workerIds)) {
                                                            // التحقق من أن أحد العمال المخصصين هو عامل إنتاج
                                                            $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                                                            $workersCheck = $db->queryOne(
                                                                "SELECT COUNT(*) as count FROM users WHERE id IN ($placeholders) AND role = 'production'",
                                                                $workerIds
                                                            );
                                                            if ($workersCheck && (int)$workersCheck['count'] > 0) {
                                                                $isTaskForProduction = true;
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                // السماح لأي عامل إنتاج بتغيير حالة أي مهمة مخصصة لعامل إنتاج
                                                if ($isTaskForProduction): 
                                                ?>
                                                    <?php if ($task['status'] === 'pending'): ?>
                                                        <button type="button" class="btn btn-outline-info" onclick="submitTaskAction('receive_task', <?php echo (int) $task['id']; ?>)">
                                                            <i class="bi bi-check-circle me-1"></i>استلام
                                                        </button>
                                                    <?php elseif ($task['status'] === 'received'): ?>
                                                        <button type="button" class="btn btn-outline-primary" onclick="submitTaskAction('start_task', <?php echo (int) $task['id']; ?>)">
                                                            <i class="bi bi-play-circle me-1"></i>بدء
                                                        </button>
                                                    <?php elseif ($task['status'] === 'in_progress'): ?>
                                                        <button type="button" class="btn btn-outline-success" onclick="submitTaskAction('complete_task', <?php echo (int) $task['id']; ?>)">
                                                            <i class="bi bi-check2-circle me-1"></i>إكمال
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($isManager): ?>
                                                <button type="button" class="btn btn-outline-secondary" onclick="viewTask(<?php echo (int) $task['id']; ?>)">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteTask(<?php echo (int) $task['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="my-3" aria-label="Task pagination">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php
                                $paramsForPage = $_GET;
                                $paramsForPage['p'] = $i;
                                $url = '?' . http_build_query($paramsForPage);
                                ?>
                                <li class="page-item <?php echo $pageNum === $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo tasksHtml($url); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isManager): ?>
<div class="modal fade" id="addTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="" id="addTaskForm">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة مهمة جديدة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_task">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">نوع المهمة <span class="text-danger">*</span></label>
                            <select class="form-select" name="task_type" id="task_type" required>
                                <option value="general">مهمة عامة</option>
                                <option value="production">مهمة إنتاج</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">العنوان <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="task_title" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">الوصف</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="تفاصيل المهمة"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">المخصص إلى</label>
                            <select class="form-select" name="assigned_to">
                                <option value="0">غير محدد</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo (int) $user['id']; ?>"><?php echo tasksHtml($user['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="normal" selected>عادية</option>
                                <option value="low">منخفضة</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">تاريخ الاستحقاق</label>
                            <input type="date" class="form-control" name="due_date">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ملاحظات إضافية</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="border rounded p-3 mt-3" id="production_fields" style="display: none;">
                        <h6 class="fw-bold mb-3">بيانات مهمة الإنتاج</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">المنتج <span class="text-danger">*</span></label>
                                <select class="form-select" name="product_id" id="product_id">
                                    <option value="0">اختر المنتج</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo (int) $product['id']; ?>"><?php echo tasksHtml($product['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الكمية <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" class="form-control" name="quantity" id="quantity" placeholder="0.00">
                            </div>
                        </div>
                        <div class="form-text mt-2">سيتم إنشاء العنوان تلقائيًا بناءً على المنتج والكمية.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewTaskModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل المهمة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body" id="viewTaskContent"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="POST" action="" id="taskActionForm" style="display: none;">
    <input type="hidden" name="action" value="">
    <input type="hidden" name="task_id" value="">
    <input type="hidden" name="status" value="">
</form>

<script>
(function () {
    'use strict';

    const tasksDataRaw = <?php echo $tasksJson; ?>;
    const tasksData = Array.isArray(tasksDataRaw)
        ? tasksDataRaw
        : (tasksDataRaw && typeof tasksDataRaw === 'object' ? Object.values(tasksDataRaw) : []);

    const taskTypeSelect = document.getElementById('task_type');
    const productionFields = document.getElementById('production_fields');
    const productSelect = document.getElementById('product_id');
    const quantityInput = document.getElementById('quantity');
    const titleInput = document.getElementById('task_title');
    const taskActionForm = document.getElementById('taskActionForm');

    function hideLoader() {
        const loader = document.getElementById('pageLoader');
        if (loader) {
            loader.style.display = 'none';
            loader.classList.add('d-none');
        }
    }

    function sanitizeText(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value)
            .replace(/[\u0000-\u001F\u007F]/g, '')
            .replace(/[&<>"'`]/g, function (char) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                    '`': '&#96;'
                })[char] || char;
            });
    }

    function sanitizeMultilineText(value) {
        return sanitizeText(value).replace(/(\r\n|\r|\n)/g, '<br>');
    }

    function toggleProductionFields() {
        if (!taskTypeSelect || !productionFields || !titleInput) {
            return;
        }

        const isProductionTask = taskTypeSelect.value === 'production';
        productionFields.style.display = isProductionTask ? 'block' : 'none';
        titleInput.readOnly = isProductionTask;

        if (!isProductionTask) {
            if (productSelect) productSelect.required = false;
            if (quantityInput) quantityInput.required = false;
            titleInput.value = '';
            return;
        }

        if (productSelect) productSelect.required = true;
        if (quantityInput) quantityInput.required = true;
        updateProductionTitle();
    }

    function updateProductionTitle() {
        if (!productSelect || !quantityInput || !titleInput) {
            return;
        }

        const productId = parseInt(productSelect.value, 10);
        const quantity = parseFloat(quantityInput.value);

        if (productId > 0 && quantity > 0) {
            const productName = sanitizeText(productSelect.options[productSelect.selectedIndex].text || 'منتج');
            titleInput.value = 'إنتاج ' + productName + ' - ' + quantity.toFixed(2) + ' قطعة';
        }
    }

    window.submitTaskAction = function (action, taskId) {
        if (!taskActionForm) return;

        taskActionForm.querySelector('input[name="action"]').value = sanitizeText(action);
        taskActionForm.querySelector('input[name="task_id"]').value = parseInt(taskId, 10) || '';
        taskActionForm.submit();
    };

    window.confirmDeleteTask = function (taskId) {
        if (window.confirm('هل أنت متأكد من حذف هذه المهمة؟')) {
            submitTaskAction('delete_task', taskId);
        }
    };

    window.viewTask = function (taskId) {
        const task = tasksData.find(function (item) {
            return parseInt(item.id, 10) === parseInt(taskId, 10);
        });

        if (!task) {
            return;
        }

        const priorityText = {
            'urgent': 'عاجلة',
            'high': 'عالية',
            'normal': 'عادية',
            'low': 'منخفضة'
        };

        const statusText = {
            'pending': 'معلقة',
            'received': 'مستلمة',
            'in_progress': 'قيد التنفيذ',
            'completed': 'مكتملة',
            'cancelled': 'ملغاة'
        };

        const title = sanitizeText(task.title || '');
        const description = task.description ? sanitizeMultilineText(task.description) : 'لا يوجد وصف';
        const productName = task.product_name ? sanitizeText(task.product_name) : '';
        const quantity = task.quantity ? sanitizeText(task.quantity) : '';
        const assignedTo = task.assigned_to_name ? sanitizeText(task.assigned_to_name) : 'غير محدد';
        const createdBy = task.created_by_name ? sanitizeText(task.created_by_name) : '';
        const dueDate = task.due_date ? sanitizeText(task.due_date) : 'غير محدد';
        const createdAt = task.created_at ? sanitizeText(task.created_at) : '';
        const notes = task.notes ? sanitizeMultilineText(task.notes) : '';

        const priorityBadgeClass = task.priority === 'urgent' ? 'danger'
            : task.priority === 'high' ? 'warning'
            : task.priority === 'normal' ? 'info'
            : 'secondary';

        const statusBadgeClass = task.status === 'pending' ? 'warning'
            : task.status === 'received' ? 'info'
            : task.status === 'in_progress' ? 'primary'
            : task.status === 'completed' ? 'success'
            : 'secondary';

        const content = `
            <div class="mb-3">
                <h5>${title}</h5>
            </div>
            <div class="mb-3">
                <strong>الوصف:</strong>
                <p>${description}</p>
            </div>
            ${productName ? `<div class="mb-3"><strong>المنتج:</strong> ${productName}</div>` : ''}
            ${quantity ? `<div class="mb-3"><strong>الكمية:</strong> ${quantity} قطعة</div>` : ''}
            <div class="row mb-3">
                <div class="col-md-6"><strong>المخصص إلى:</strong> ${assignedTo}</div>
                <div class="col-md-6"><strong>أنشئت بواسطة:</strong> ${createdBy}</div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <strong>الأولوية:</strong>
                    <span class="badge bg-${priorityBadgeClass}">${sanitizeText(priorityText[task.priority] || task.priority || '')}</span>
                </div>
                <div class="col-md-6">
                    <strong>الحالة:</strong>
                    <span class="badge bg-${statusBadgeClass}">${sanitizeText(statusText[task.status] || task.status || '')}</span>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6"><strong>تاريخ الاستحقاق:</strong> ${dueDate}</div>
                <div class="col-md-6"><strong>تاريخ الإنشاء:</strong> ${createdAt}</div>
            </div>
            ${notes ? `<div class="mb-3"><strong>ملاحظات:</strong><p>${notes}</p></div>` : ''}
        `;

        const modalContent = document.getElementById('viewTaskContent');
        if (modalContent) {
            modalContent.innerHTML = content;
        }

        const modalElement = document.getElementById('viewTaskModal');
        if (modalElement && typeof bootstrap !== 'undefined') {
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        hideLoader();
        toggleProductionFields();
    });

    window.addEventListener('load', hideLoader);

    if (taskTypeSelect) {
        taskTypeSelect.addEventListener('change', toggleProductionFields);
    }

    if (productSelect) {
        productSelect.addEventListener('change', updateProductionTitle);
    }

    if (quantityInput) {
        quantityInput.addEventListener('input', updateProductionTitle);
    }
})();
</script>

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
</script>
