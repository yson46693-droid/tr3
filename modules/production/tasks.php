<?php
/**
 * صفحة إدارة المهام
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

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';
$isManager = $currentUser['role'] === 'manager';
$isProduction = $currentUser['role'] === 'production';
$tasksRetentionLimit = getTasksRetentionLimit();

// التحقق من وجود جدول tasks وإنشاؤه/تحديثه إذا لم يكن موجوداً
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'tasks'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `tasks` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `title` varchar(255) NOT NULL,
              `description` text DEFAULT NULL,
              `assigned_to` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
              `status` enum('pending','in_progress','completed','cancelled','received') DEFAULT 'pending',
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
        $success = 'تم إنشاء جدول المهام بنجاح';
    } catch (Exception $e) {
        error_log("Error creating tasks table: " . $e->getMessage());
    }
} else {
    // إضافة الأعمدة الجديدة إذا لم تكن موجودة
    try {
        $columnsToAdd = [
            'received_at' => "ALTER TABLE tasks ADD COLUMN received_at timestamp NULL DEFAULT NULL AFTER completed_at",
            'started_at' => "ALTER TABLE tasks ADD COLUMN started_at timestamp NULL DEFAULT NULL AFTER received_at",
            'product_id' => "ALTER TABLE tasks ADD COLUMN product_id int(11) DEFAULT NULL AFTER related_id",
            'quantity' => "ALTER TABLE tasks ADD COLUMN quantity decimal(10,2) DEFAULT NULL AFTER product_id",
            'status_received' => "ALTER TABLE tasks MODIFY COLUMN status enum('pending','received','in_progress','completed','cancelled') DEFAULT 'pending'"
        ];
        
        foreach ($columnsToAdd as $columnName => $sql) {
            $columnCheck = $db->queryOne("SHOW COLUMNS FROM tasks LIKE '$columnName'");
            if (empty($columnCheck)) {
                try {
                    $db->execute($sql);
                } catch (Exception $e) {
                    // قد تكون الأعمدة موجودة بالفعل أو نوع enum مختلف
                    if ($columnName === 'status_received') {
                        // تحديث enum status يدوياً
                        try {
                            $db->execute("ALTER TABLE tasks MODIFY COLUMN status enum('pending','received','in_progress','completed','cancelled') DEFAULT 'pending'");
                        } catch (Exception $e2) {
                            // تجاهل الخطأ إذا كان enum مختلف
                        }
                    }
                }
            }
        }
        
        // إضافة foreign key لـ product_id إذا لم يكن موجوداً
        $foreignKeyCheck = $db->queryOne("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = 'tasks' 
            AND COLUMN_NAME = 'product_id' 
            AND CONSTRAINT_NAME != 'PRIMARY'
        ");
        if (empty($foreignKeyCheck)) {
            try {
                $db->execute("ALTER TABLE tasks ADD CONSTRAINT tasks_ibfk_3 FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL");
            } catch (Exception $e) {
                // تجاهل الخطأ إذا كان موجوداً بالفعل
            }
        }
    } catch (Exception $e) {
        error_log("Error updating tasks table: " . $e->getMessage());
    }
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_task') {
        // فقط المدير يمكنه إنشاء مهام
        if (!$isManager) {
            $error = 'غير مصرح لك بإنشاء مهام';
        } else {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $assignedTo = intval($_POST['assigned_to'] ?? 0);
            $priority = $_POST['priority'] ?? 'normal';
            $dueDate = $_POST['due_date'] ?? null;
            $relatedType = trim($_POST['related_type'] ?? '');
            $relatedId = intval($_POST['related_id'] ?? 0);
            $productId = intval($_POST['product_id'] ?? 0);
            $quantity = floatval($_POST['quantity'] ?? 0);
            $taskType = $_POST['task_type'] ?? 'general';
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($title)) {
                $error = 'يجب إدخال عنوان المهمة';
            } elseif ($taskType === 'production' && $productId <= 0) {
                $error = 'يجب اختيار منتج لمهمة الإنتاج';
            } elseif ($taskType === 'production' && $quantity <= 0) {
                $error = 'يجب إدخال كمية صحيحة لمهمة الإنتاج';
            } else {
                try {
                    // إذا كانت مهمة إنتاج، تحديث العنوان
                    if ($taskType === 'production' && $productId > 0) {
                        $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                        if ($product) {
                            $title = 'إنتاج ' . $product['name'] . ' - ' . $quantity . ' قطعة';
                        }
                    }
                    
                    $columns = ['title', 'description', 'created_by', 'priority', 'status'];
                    $values = [$title, $description, $currentUser['id'], $priority, 'pending'];
                    $placeholders = ['?', '?', '?', '?', '?'];
                    
                    if ($assignedTo > 0) {
                        $columns[] = 'assigned_to';
                        $values[] = $assignedTo;
                        $placeholders[] = '?';
                    }
                    
                    if ($dueDate) {
                        $columns[] = 'due_date';
                        $values[] = $dueDate;
                        $placeholders[] = '?';
                    }
                    
                    if ($relatedType && $relatedId > 0) {
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
                    
                    if ($notes) {
                        $columns[] = 'notes';
                        $values[] = $notes;
                        $placeholders[] = '?';
                    }
                    
                    $sql = "INSERT INTO tasks (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $result = $db->execute($sql, $values);

                    enforceTasksRetentionLimit($db, $tasksRetentionLimit);

                    logAudit($currentUser['id'], 'add_task', 'tasks', $result['insert_id'], null, ['title' => $title, 'type' => $taskType]);
                    $success = 'تم إضافة المهمة بنجاح';
                } catch (Exception $e) {
                    $error = 'حدث خطأ في إضافة المهمة: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'receive_task') {
        // عمال الإنتاج يمكنهم استلام المهام
        $taskId = intval($_POST['task_id'] ?? 0);
        
        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح';
        } else {
            // التحقق من أن المهمة مخصصة لهذا العامل
            $task = $db->queryOne("SELECT assigned_to, status FROM tasks WHERE id = ?", [$taskId]);
            if (!$task) {
                $error = 'المهمة غير موجودة';
            } elseif ($task['assigned_to'] != $currentUser['id']) {
                $error = 'هذه المهمة غير مخصصة لك';
            } else {
                try {
                    $db->execute("UPDATE tasks SET status = 'received', received_at = NOW() WHERE id = ?", [$taskId]);
                    logAudit($currentUser['id'], 'receive_task', 'tasks', $taskId, null, null);
                    $success = 'تم استلام المهمة بنجاح';
                } catch (Exception $e) {
                    $error = 'حدث خطأ في استلام المهمة: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'start_task') {
        // عمال الإنتاج يمكنهم بدء المهام
        $taskId = intval($_POST['task_id'] ?? 0);
        
        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح';
        } else {
            $task = $db->queryOne("SELECT assigned_to, status FROM tasks WHERE id = ?", [$taskId]);
            if (!$task) {
                $error = 'المهمة غير موجودة';
            } elseif ($task['assigned_to'] != $currentUser['id']) {
                $error = 'هذه المهمة غير مخصصة لك';
            } else {
                try {
                    $db->execute("UPDATE tasks SET status = 'in_progress', started_at = NOW() WHERE id = ?", [$taskId]);
                    logAudit($currentUser['id'], 'start_task', 'tasks', $taskId, null, null);
                    $success = 'تم بدء المهمة بنجاح';
                } catch (Exception $e) {
                    $error = 'حدث خطأ في بدء المهمة: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'complete_task') {
        // عمال الإنتاج يمكنهم إكمال المهام
        $taskId = intval($_POST['task_id'] ?? 0);
        
        if ($taskId <= 0) {
            $error = 'معرف المهمة غير صحيح';
        } else {
            $task = $db->queryOne("SELECT assigned_to, status, title, created_by FROM tasks WHERE id = ?", [$taskId]);
            if (!$task) {
                $error = 'المهمة غير موجودة';
            } elseif ($task['assigned_to'] != $currentUser['id']) {
                $error = 'هذه المهمة غير مخصصة لك';
            } else {
                try {
                    $db->execute("UPDATE tasks SET status = 'completed', completed_at = NOW() WHERE id = ?", [$taskId]);
                    logAudit($currentUser['id'], 'complete_task', 'tasks', $taskId, null, null);
                    $success = 'تم إكمال المهمة بنجاح';

                    try {
                        $taskTitle = $task['title'] ?? ('مهمة #' . $taskId);
                        $taskLink = getRelativeUrl('production.php?page=tasks');
                        createNotification(
                            $currentUser['id'],
                            'تم إكمال المهمة',
                            'تم تسجيل المهمة "' . $taskTitle . '" كمكتملة.',
                            'success',
                            $taskLink
                        );

                        if (!empty($task['created_by']) && $task['created_by'] != $currentUser['id']) {
                            $creatorLink = getRelativeUrl('manager.php?page=tasks');
                            $workerName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'عامل الإنتاج';
                            createNotification(
                                (int)$task['created_by'],
                                'تم إكمال مهمة الإنتاج',
                                $workerName . ' أكمل المهمة "' . $taskTitle . '".',
                                'success',
                                $creatorLink
                            );
                        }
                    } catch (Exception $notifyError) {
                        error_log('Task completion notification error: ' . $notifyError->getMessage());
                    }
                } catch (Exception $e) {
                    $error = 'حدث خطأ في إكمال المهمة: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'change_status') {
        // فقط المدير يمكنه تغيير الحالة مباشرة
        if (!$isManager) {
            $error = 'غير مصرح لك بتغيير حالة المهمة';
        } else {
            $taskId = intval($_POST['task_id'] ?? 0);
            $status = $_POST['status'] ?? 'pending';
            
            if ($taskId <= 0) {
                $error = 'معرف المهمة غير صحيح';
            } else {
                try {
                    $setParts = ['status = ?'];
                    $values = [$status];
                    
                    if ($status === 'completed') {
                        $setParts[] = 'completed_at = NOW()';
                    } else {
                        $setParts[] = 'completed_at = NULL';
                    }
                    
                    if ($status === 'received') {
                        $setParts[] = 'received_at = NOW()';
                    }
                    
                    if ($status === 'in_progress') {
                        $setParts[] = 'started_at = NOW()';
                    }
                    
                    $values[] = $taskId;
                    
                    $sql = "UPDATE tasks SET " . implode(', ', $setParts) . " WHERE id = ?";
                    $db->execute($sql, $values);
                    
                    logAudit($currentUser['id'], 'change_task_status', 'tasks', $taskId, null, ['status' => $status]);
                    $success = 'تم تحديث حالة المهمة بنجاح';

                    if ($status === 'completed') {
                        try {
                            $taskDetails = $db->queryOne(
                                "SELECT assigned_to, title FROM tasks WHERE id = ?",
                                [$taskId]
                            );
                            if ($taskDetails && !empty($taskDetails['assigned_to'])) {
                                $taskTitle = $taskDetails['title'] ?? ('مهمة #' . $taskId);
                                createNotification(
                                    (int)$taskDetails['assigned_to'],
                                    'تم تحديث حالة المهمة',
                                    'قام المدير بتعليم المهمة "' . $taskTitle . '" كمكتملة.',
                                    'success',
                                    getRelativeUrl('production.php?page=tasks')
                                );
                            }
                        } catch (Exception $notifyError) {
                            error_log('Manager status change notification error: ' . $notifyError->getMessage());
                        }
                    }
                } catch (Exception $e) {
                    $error = 'حدث خطأ في تحديث حالة المهمة: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_task') {
        // فقط المدير يمكنه حذف المهام
        if (!$isManager) {
            $error = 'غير مصرح لك بحذف المهام';
        } else {
            $taskId = intval($_POST['task_id'] ?? 0);
            
            if ($taskId <= 0) {
                $error = 'معرف المهمة غير صحيح';
            } else {
                try {
                    $db->execute("DELETE FROM tasks WHERE id = ?", [$taskId]);
                    logAudit($currentUser['id'], 'delete_task', 'tasks', $taskId, null, null);
                    $success = 'تم حذف المهمة بنجاح';
                } catch (Exception $e) {
                    $error = 'حدث خطأ في حذف المهمة: ' . $e->getMessage();
                }
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
$statusFilter = $_GET['status'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$assignedFilter = intval($_GET['assigned'] ?? 0);

// بناء استعلام SQL
$whereConditions = [];
$params = [];

if ($search) {
    $whereConditions[] = "(t.title LIKE ? OR t.description LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($statusFilter) {
    $whereConditions[] = "t.status = ?";
    $params[] = $statusFilter;
} else {
    $whereConditions[] = "t.status != 'cancelled'";
}

if ($priorityFilter) {
    $whereConditions[] = "t.priority = ?";
    $params[] = $priorityFilter;
}

if ($assignedFilter > 0) {
    $whereConditions[] = "t.assigned_to = ?";
    $params[] = $assignedFilter;
}

// إذا كان المستخدم عامل إنتاج، عرض فقط المهام المخصصة له
if ($isProduction) {
    $whereConditions[] = "t.assigned_to = ?";
    $params[] = $currentUser['id'];
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(' AND ', $whereConditions) : '';

// الحصول على إجمالي المهام
$totalQuery = "SELECT COUNT(*) as total FROM tasks t $whereClause";
$totalResult = $db->queryOne($totalQuery, $params);
$totalTasks = $totalResult['total'] ?? 0;
$totalPages = ceil($totalTasks / $perPage);

// الحصول على المهام
$tasksQuery = "SELECT t.*, 
     u1.full_name as assigned_to_name, 
     u2.full_name as created_by_name,
     p.name as product_name
     FROM tasks t
     LEFT JOIN users u1 ON t.assigned_to = u1.id
     LEFT JOIN users u2 ON t.created_by = u2.id
     LEFT JOIN products p ON t.product_id = p.id
     $whereClause
     ORDER BY 
     CASE priority 
         WHEN 'urgent' THEN 1 
         WHEN 'high' THEN 2 
         WHEN 'normal' THEN 3 
         WHEN 'low' THEN 4 
     END,
     due_date ASC,
     created_at DESC
     LIMIT ? OFFSET ?";
     
$queryParams = array_merge($params, [$perPage, $offset]);
$tasks = $db->query($tasksQuery, $queryParams);

// الحصول على جميع المستخدمين للقائمة المنسدلة (فقط عمال الإنتاج)
$users = $db->query("SELECT id, full_name, role FROM users WHERE status = 'active' AND role = 'production' ORDER BY full_name");

// الحصول على المنتجات
$products = $db->query("SELECT id, name FROM products WHERE status = 'active' ORDER BY name");

// إحصائيات
$statsWhere = $isProduction ? "WHERE assigned_to = " . $currentUser['id'] : "";
$stats = [
    'total' => $db->queryOne("SELECT COUNT(*) as total FROM tasks $statsWhere")['total'] ?? 0,
    'pending' => $db->queryOne("SELECT COUNT(*) as total FROM tasks $statsWhere AND status = 'pending'")['total'] ?? 0,
    'received' => $db->queryOne("SELECT COUNT(*) as total FROM tasks $statsWhere AND status = 'received'")['total'] ?? 0,
    'in_progress' => $db->queryOne("SELECT COUNT(*) as total FROM tasks $statsWhere AND status = 'in_progress'")['total'] ?? 0,
    'completed' => $db->queryOne("SELECT COUNT(*) as total FROM tasks $statsWhere AND status = 'completed'")['total'] ?? 0,
    'overdue' => $db->queryOne("SELECT COUNT(*) as total FROM tasks $statsWhere AND status != 'completed' AND due_date < CURDATE()")['total'] ?? 0
];

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
?>

<div class="container-fluid">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row mb-3">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-list-check me-2"></i>إدارة المهام</h2>
                <?php if ($isManager): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
                        <i class="bi bi-plus-circle me-2"></i>إضافة مهمة جديدة
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- إحصائيات -->
    <div class="row mb-3">
        <div class="col-md-2 col-sm-6 mb-2">
            <div class="card text-center border-primary">
                <div class="card-body p-2">
                    <h5 class="card-title text-primary mb-0" style="font-size: 1.5rem;"><?php echo $stats['total']; ?></h5>
                    <p class="card-text text-muted mb-0" style="font-size: 0.8rem;">إجمالي المهام</p>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <div class="card text-center border-warning">
                <div class="card-body p-2">
                    <h5 class="card-title text-warning mb-0" style="font-size: 1.5rem;"><?php echo $stats['pending']; ?></h5>
                    <p class="card-text text-muted mb-0" style="font-size: 0.8rem;">معلقة</p>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <div class="card text-center border-info">
                <div class="card-body p-2">
                    <h5 class="card-title text-info mb-0" style="font-size: 1.5rem;"><?php echo $stats['received']; ?></h5>
                    <p class="card-text text-muted mb-0" style="font-size: 0.8rem;">مستلمة</p>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <div class="card text-center border-info">
                <div class="card-body p-2">
                    <h5 class="card-title text-info mb-0" style="font-size: 1.5rem;"><?php echo $stats['in_progress']; ?></h5>
                    <p class="card-text text-muted mb-0" style="font-size: 0.8rem;">قيد التنفيذ</p>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <div class="card text-center border-success">
                <div class="card-body p-2">
                    <h5 class="card-title text-success mb-0" style="font-size: 1.5rem;"><?php echo $stats['completed']; ?></h5>
                    <p class="card-text text-muted mb-0" style="font-size: 0.8rem;">مكتملة</p>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6 mb-2">
            <div class="card text-center border-danger">
                <div class="card-body p-2">
                    <h5 class="card-title text-danger mb-0" style="font-size: 1.5rem;"><?php echo $stats['overdue']; ?></h5>
                    <p class="card-text text-muted mb-0" style="font-size: 0.8rem;">متأخرة</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- فلاتر البحث - سطر واحد -->
    <div class="card mb-3">
        <div class="card-body p-2">
            <form method="GET" action="" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="tasks">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-0" style="font-size: 0.85rem;">بحث</label>
                    <input type="text" class="form-control form-control-sm" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="عنوان أو وصف">
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label mb-0" style="font-size: 0.85rem;">الحالة</label>
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
                    <label class="form-label mb-0" style="font-size: 0.85rem;">الأولوية</label>
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
                    <label class="form-label mb-0" style="font-size: 0.85rem;">المخصص إلى</label>
                    <select class="form-select form-select-sm" name="assigned">
                        <option value="0">الكل</option>
                        <?php 
                        foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $assignedFilter === $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
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
                <div class="col-md-2 col-sm-6">
                    <a href="?page=tasks" class="btn btn-secondary btn-sm w-100">
                        <i class="bi bi-x me-1"></i>إعادة تعيين
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- جدول المهام -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($tasks)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <p class="text-muted mt-3">لا توجد مهام</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>العنوان</th>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>المخصص إلى</th>
                                <th>الأولوية</th>
                                <th>الحالة</th>
                                <th>تاريخ الاستحقاق</th>
                                <th>أنشئت بواسطة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $index => $task): ?>
                                <?php
                                $overdue = $task['due_date'] && !in_array($task['status'], ['completed', 'cancelled']) && strtotime($task['due_date']) < time();
                                $priorityClass = [
                                    'urgent' => 'danger',
                                    'high' => 'warning',
                                    'normal' => 'info',
                                    'low' => 'secondary'
                                ];
                                $statusClass = [
                                    'pending' => 'warning',
                                    'received' => 'info',
                                    'in_progress' => 'primary',
                                    'completed' => 'success',
                                    'cancelled' => 'secondary'
                                ];
                                $statusText = [
                                    'pending' => 'معلقة',
                                    'received' => 'مستلمة',
                                    'in_progress' => 'قيد التنفيذ',
                                    'completed' => 'مكتملة',
                                    'cancelled' => 'ملغاة'
                                ];
                                ?>
                                <tr class="<?php echo $overdue ? 'table-danger' : ''; ?>">
                                    <td><?php echo ($pageNum - 1) * $perPage + $index + 1; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                        <?php if ($overdue): ?>
                                            <span class="badge bg-danger ms-1">متأخرة</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $task['product_name'] ? htmlspecialchars($task['product_name']) : '-'; ?></td>
                                    <td><?php echo $task['quantity'] ? number_format($task['quantity'], 2) . ' قطعة' : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($task['assigned_to_name'] ?? 'غير محدد'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $priorityClass[$task['priority']] ?? 'secondary'; ?>">
                                            <?php
                                            $priorityText = ['urgent' => 'عاجلة', 'high' => 'عالية', 'normal' => 'عادية', 'low' => 'منخفضة'];
                                            echo $priorityText[$task['priority']] ?? $task['priority'];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusClass[$task['status']] ?? 'secondary'; ?>">
                                            <?php echo $statusText[$task['status']] ?? $task['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($task['due_date']): ?>
                                            <?php echo date('Y-m-d', strtotime($task['due_date'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($task['created_by_name'] ?? ''); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($isProduction && $task['assigned_to'] == $currentUser['id']): ?>
                                                <?php if ($task['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-info" onclick="receiveTask(<?php echo $task['id']; ?>)" title="استلام المهمة">
                                                        <i class="bi bi-check-circle me-1"></i>استلام
                                                    </button>
                                                <?php elseif ($task['status'] === 'received'): ?>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="startTask(<?php echo $task['id']; ?>)" title="بدء العمل">
                                                        <i class="bi bi-play-circle me-1"></i>بدء
                                                    </button>
                                                <?php elseif ($task['status'] === 'in_progress'): ?>
                                                    <button type="button" class="btn btn-sm btn-success" onclick="completeTask(<?php echo $task['id']; ?>)" title="إكمال المهمة">
                                                        <i class="bi bi-check-circle-fill me-1"></i>إكمال
                                                    </button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($isManager): ?>
                                                <button type="button" class="btn btn-sm btn-info" onclick="viewTask(<?php echo $task['id']; ?>)" title="عرض">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteTask(<?php echo $task['id']; ?>)" title="حذف">
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
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php
                            $queryParams = $_GET;
                            for ($i = 1; $i <= $totalPages; $i++):
                                $queryParams['p'] = $i;
                                $url = '?' . http_build_query($queryParams);
                            ?>
                                <li class="page-item <?php echo $pageNum === $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($url); ?>"><?php echo $i; ?></a>
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
<!-- Modal إضافة مهمة -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة مهمة جديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="addTaskForm">
                <input type="hidden" name="action" value="add_task">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">نوع المهمة <span class="text-danger">*</span></label>
                        <select class="form-select" name="task_type" id="task_type" required onchange="toggleProductionFields()">
                            <option value="general">مهمة عامة</option>
                            <option value="production">مهمة إنتاج</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">عنوان المهمة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" id="task_title" required>
                        <small class="text-muted">سيتم ملء العنوان تلقائياً لمهام الإنتاج</small>
                    </div>
                    <div id="production_fields" style="display: none;">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">المنتج <span class="text-danger">*</span></label>
                                <select class="form-select" name="product_id" id="product_id">
                                    <option value="0">اختر المنتج</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">الكمية <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" class="form-control" name="quantity" id="quantity" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الوصف</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">المخصص إلى</label>
                            <select class="form-select" name="assigned_to">
                                <option value="0">غير محدد</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الأولوية</label>
                            <select class="form-select" name="priority">
                                <option value="low">منخفضة</option>
                                <option value="normal" selected>عادية</option>
                                <option value="high">عالية</option>
                                <option value="urgent">عاجلة</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">تاريخ الاستحقاق</label>
                        <input type="date" class="form-control" name="due_date">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="2"></textarea>
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

<!-- Modal عرض المهمة -->
<div class="modal fade" id="viewTaskModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل المهمة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewTaskContent">
                <!-- سيتم ملؤه بالـ JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
let tasksData = <?php echo json_encode($tasks); ?>;

function toggleProductionFields() {
    const taskType = document.getElementById('task_type').value;
    const productionFields = document.getElementById('production_fields');
    const productId = document.getElementById('product_id');
    const quantity = document.getElementById('quantity');
    const taskTitle = document.getElementById('task_title');
    
    if (taskType === 'production') {
        productionFields.style.display = 'block';
        productId.required = true;
        quantity.required = true;
        taskTitle.readOnly = true;
        updateProductionTitle();
    } else {
        productionFields.style.display = 'none';
        productId.required = false;
        quantity.required = false;
        taskTitle.readOnly = false;
        taskTitle.value = '';
    }
}

function updateProductionTitle() {
    const productId = document.getElementById('product_id');
    const quantity = document.getElementById('quantity');
    const taskTitle = document.getElementById('task_title');
    
    if (productId.value > 0 && quantity.value > 0) {
        const productName = productId.options[productId.selectedIndex].text;
        taskTitle.value = 'إنتاج ' + productName + ' - ' + quantity.value + ' قطعة';
    }
}

document.getElementById('product_id')?.addEventListener('change', updateProductionTitle);
document.getElementById('quantity')?.addEventListener('input', updateProductionTitle);

function viewTask(taskId) {
    const task = tasksData.find(t => t.id == taskId);
    if (!task) return;
    
    const priorityText = {'urgent': 'عاجلة', 'high': 'عالية', 'normal': 'عادية', 'low': 'منخفضة'};
    const statusText = {'pending': 'معلقة', 'received': 'مستلمة', 'in_progress': 'قيد التنفيذ', 'completed': 'مكتملة', 'cancelled': 'ملغاة'};
    
    const content = `
        <div class="mb-3">
            <h5>${task.title}</h5>
        </div>
        <div class="mb-3">
            <strong>الوصف:</strong>
            <p>${task.description || 'لا يوجد وصف'}</p>
        </div>
        ${task.product_name ? `<div class="mb-3"><strong>المنتج:</strong> ${task.product_name}</div>` : ''}
        ${task.quantity ? `<div class="mb-3"><strong>الكمية:</strong> ${task.quantity} قطعة</div>` : ''}
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>المخصص إلى:</strong> ${task.assigned_to_name || 'غير محدد'}
            </div>
            <div class="col-md-6">
                <strong>أنشئت بواسطة:</strong> ${task.created_by_name || ''}
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>الأولوية:</strong> 
                <span class="badge bg-${task.priority === 'urgent' ? 'danger' : task.priority === 'high' ? 'warning' : task.priority === 'normal' ? 'info' : 'secondary'}">
                    ${priorityText[task.priority] || task.priority}
                </span>
            </div>
            <div class="col-md-6">
                <strong>الحالة:</strong> 
                <span class="badge bg-${task.status === 'pending' ? 'warning' : task.status === 'received' ? 'info' : task.status === 'in_progress' ? 'primary' : task.status === 'completed' ? 'success' : 'secondary'}">
                    ${statusText[task.status] || task.status}
                </span>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>تاريخ الاستحقاق:</strong> ${task.due_date || 'غير محدد'}
            </div>
            <div class="col-md-6">
                <strong>تاريخ الإنشاء:</strong> ${task.created_at || ''}
            </div>
        </div>
        ${task.notes ? `<div class="mb-3"><strong>ملاحظات:</strong><p>${task.notes}</p></div>` : ''}
    `;
    
    document.getElementById('viewTaskContent').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewTaskModal')).show();
}

function receiveTask(taskId) {
    if (!confirm('هل أنت متأكد من استلام هذه المهمة؟')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="receive_task">
        <input type="hidden" name="task_id" value="${taskId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function startTask(taskId) {
    if (!confirm('هل أنت متأكد من بدء العمل على هذه المهمة؟')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="start_task">
        <input type="hidden" name="task_id" value="${taskId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function completeTask(taskId) {
    if (!confirm('هل أنت متأكد من إكمال هذه المهمة؟')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="complete_task">
        <input type="hidden" name="task_id" value="${taskId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function deleteTask(taskId) {
    if (!confirm('هل أنت متأكد من حذف هذه المهمة؟')) return;
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_task">
        <input type="hidden" name="task_id" value="${taskId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

document.addEventListener('DOMContentLoaded', function() {
    const pageLoader = document.getElementById('pageLoader');
    if (pageLoader) {
        pageLoader.style.display = 'none';
        pageLoader.style.visibility = 'hidden';
        pageLoader.classList.add('hidden');
    }

    if (typeof toggleProductionFields === 'function') {
        toggleProductionFields();
    }
});
</script>
