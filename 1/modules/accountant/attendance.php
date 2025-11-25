<?php
/**
 * صفحة إدارة الحضور للمحاسب
 * Attendance Management Page for Accountant
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$userFilter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Build query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(u.username LIKE ? OR u.full_name LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam]);
}

if ($userFilter > 0) {
    $whereConditions[] = "a.user_id = ?";
    $params[] = $userFilter;
}

if (!empty($statusFilter)) {
    $whereConditions[] = "a.status = ?";
    $params[] = $statusFilter;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "a.date >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "a.date <= ?";
    $params[] = $dateTo;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// استبعاد المديرين من الاستعلامات
$excludeManagersClause = "AND u.role != 'manager'";

// Get total count
$totalCountQuery = "SELECT COUNT(*) as total FROM attendance a 
                    LEFT JOIN users u ON a.user_id = u.id 
                    " . (!empty($whereConditions) ? $whereClause . " " . $excludeManagersClause : "WHERE " . $excludeManagersClause);
$totalCount = $db->queryOne($totalCountQuery, $params)['total'] ?? 0;
$totalPages = ceil($totalCount / $perPage);

// Get attendance records
$attendanceQuery = "SELECT a.*, u.username, u.full_name, u.role 
                    FROM attendance a 
                    LEFT JOIN users u ON a.user_id = u.id 
                    " . (!empty($whereConditions) ? $whereClause . " " . $excludeManagersClause : "WHERE " . $excludeManagersClause) . " 
                    ORDER BY a.date DESC, a.check_in DESC 
                    LIMIT ? OFFSET ?";
$queryParams = array_merge($params, [$perPage, $offset]);
$attendanceRecords = $db->query($attendanceQuery, $queryParams);

// Get all users for filter (استبعاد المديرين)
$allUsers = $db->query("SELECT id, username, full_name FROM users WHERE status = 'active' AND role != 'manager' ORDER BY username");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $userId = intval($_POST['user_id'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $checkIn = $_POST['check_in'] ?? null;
        $checkOut = $_POST['check_out'] ?? null;
        $status = $_POST['status'] ?? 'present';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($userId <= 0) {
            $error = 'يجب اختيار المستخدم';
        } else {
            // Check if attendance already exists for this user and date
            $existing = $db->queryOne(
                "SELECT id FROM attendance WHERE user_id = ? AND date = ?",
                [$userId, $date]
            );
            
            if ($existing) {
                $error = 'يوجد سجل حضور لهذا المستخدم في هذا التاريخ';
            } else {
                try {
                    $db->execute(
                        "INSERT INTO attendance (user_id, date, check_in, check_out, status, notes) VALUES (?, ?, ?, ?, ?, ?)",
                        [$userId, $date, $checkIn ?: null, $checkOut ?: null, $status, $notes ?: null]
                    );
                    $success = 'تم إضافة سجل الحضور بنجاح';
                    echo '<script>window.location.href = "' . getDashboardUrl('accountant') . '?page=attendance&success=' . urlencode($success) . '";</script>';
                    exit;
                } catch (Exception $e) {
                    $error = 'حدث خطأ: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $userId = intval($_POST['user_id'] ?? 0);
        $date = $_POST['date'] ?? date('Y-m-d');
        $checkIn = $_POST['check_in'] ?? null;
        $checkOut = $_POST['check_out'] ?? null;
        $status = $_POST['status'] ?? 'present';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($id <= 0 || $userId <= 0) {
            $error = 'بيانات غير صحيحة';
        } else {
            try {
                $db->execute(
                    "UPDATE attendance SET user_id = ?, date = ?, check_in = ?, check_out = ?, status = ?, notes = ? WHERE id = ?",
                    [$userId, $date, $checkIn ?: null, $checkOut ?: null, $status, $notes ?: null, $id]
                );
                $success = 'تم تحديث سجل الحضور بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('accountant') . '?page=attendance&success=' . urlencode($success) . '";</script>';
                exit;
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $db->execute("DELETE FROM attendance WHERE id = ?", [$id]);
                $success = 'تم حذف سجل الحضور بنجاح';
                echo '<script>window.location.href = "' . getDashboardUrl('accountant') . '?page=attendance&success=' . urlencode($success) . '";</script>';
                exit;
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    }
}

// Get success message from URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Get attendance record for editing
$editAttendance = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editAttendance = $db->queryOne(
        "SELECT a.*, u.username, u.full_name FROM attendance a 
         LEFT JOIN users u ON a.user_id = u.id 
         WHERE a.id = ?",
        [$editId]
    );
}

// Calculate total hours for a record
function calculateHours($checkIn, $checkOut) {
    if (!$checkIn || !$checkOut) {
        return '-';
    }
    $in = strtotime($checkIn);
    $out = strtotime($checkOut);
    $hours = ($out - $in) / 3600;
    return number_format($hours, 2) . ' ساعة';
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i><?php echo isset($lang['attendance']) ? $lang['attendance'] : 'الحضور'; ?> (<?php echo $totalCount; ?>)</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
            <i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?>
        </button>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Search and Filter -->
        <?php
        require_once __DIR__ . '/../../includes/path_helper.php';
        $currentUrl = getRelativeUrl('dashboard/accountant.php');
        ?>
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="mb-4">
            <input type="hidden" name="page" value="attendance">
            <div class="row g-3">
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث...'; ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="user_id">
                        <option value=""><?php echo isset($lang['all_users']) ? $lang['all_users'] : 'جميع المستخدمين'; ?></option>
                        <?php 
                        require_once __DIR__ . '/../../includes/path_helper.php';
                        $userFilterValid = isValidSelectValue($userFilter, $allUsers, 'id');
                        foreach ($allUsers as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $userFilterValid && $userFilter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value=""><?php echo isset($lang['all']) ? $lang['all'] : 'جميع الحالات'; ?></option>
                        <option value="present" <?php echo $statusFilter === 'present' ? 'selected' : ''; ?>><?php echo isset($lang['present']) ? $lang['present'] : 'حاضر'; ?></option>
                        <option value="absent" <?php echo $statusFilter === 'absent' ? 'selected' : ''; ?>><?php echo isset($lang['absent']) ? $lang['absent'] : 'غائب'; ?></option>
                        <option value="late" <?php echo $statusFilter === 'late' ? 'selected' : ''; ?>><?php echo isset($lang['late']) ? $lang['late'] : 'متأخر'; ?></option>
                        <option value="half_day" <?php echo $statusFilter === 'half_day' ? 'selected' : ''; ?>><?php echo isset($lang['half_day']) ? $lang['half_day'] : 'نصف يوم'; ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Attendance Table -->
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php echo isset($lang['username']) ? $lang['username'] : 'المستخدم'; ?></th>
                        <th><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?></th>
                        <th><?php echo isset($lang['check_in']) ? $lang['check_in'] : 'وقت الدخول'; ?></th>
                        <th><?php echo isset($lang['check_out']) ? $lang['check_out'] : 'وقت الخروج'; ?></th>
                        <th><?php echo isset($lang['total_hours']) ? $lang['total_hours'] : 'إجمالي الساعات'; ?></th>
                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                        <th><?php echo isset($lang['actions']) ? $lang['actions'] : 'الإجراءات'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendanceRecords)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <i class="bi bi-inbox me-2"></i><?php echo isset($lang['no_data']) ? $lang['no_data'] : 'لا توجد بيانات'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRecords as $index => $record): ?>
                            <tr>
                                <td><?php echo $offset + $index + 1; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($record['full_name'] ?? $record['username']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($record['username']); ?></small>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($record['date'])); ?></td>
                                <td><?php echo $record['check_in'] ? date('H:i', strtotime($record['check_in'])) : '<span class="text-muted">-</span>'; ?></td>
                                <td><?php echo $record['check_out'] ? date('H:i', strtotime($record['check_out'])) : '<span class="text-muted">-</span>'; ?></td>
                                <td><?php echo calculateHours($record['check_in'], $record['check_out']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $record['status'] === 'present' ? 'success' : 
                                            ($record['status'] === 'absent' ? 'danger' : 
                                            ($record['status'] === 'late' ? 'warning' : 'info')); 
                                    ?>">
                                        <?php echo isset($lang[$record['status']]) ? $lang[$record['status']] : $record['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="?page=attendance&edit=<?php echo $record['id']; ?>" class="btn btn-outline" data-bs-toggle="tooltip" title="<?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteAttendance(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['full_name'] ?? $record['username'], ENT_QUOTES); ?>', '<?php echo $record['date']; ?>')" data-bs-toggle="tooltip" title="<?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?>">
                                            <i class="bi bi-trash"></i>
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
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=attendance&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $userFilter ? '&user_id=' . $userFilter : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=attendance&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $userFilter ? '&user_id=' . $userFilter : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=attendance&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $userFilter ? '&user_id=' . $userFilter : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=attendance&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $userFilter ? '&user_id=' . $userFilter : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=attendance&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $userFilter ? '&user_id=' . $userFilter : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Add Attendance Modal -->
<div class="modal fade" id="addAttendanceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?> <?php echo isset($lang['attendance']) ? $lang['attendance'] : 'سجل حضور'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['username']) ? $lang['username'] : 'المستخدم'; ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_id" required>
                                <option value=""><?php echo isset($lang['select']) ? $lang['select'] : 'اختر...'; ?></option>
                                <?php foreach ($allUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['check_in']) ? $lang['check_in'] : 'وقت الدخول'; ?></label>
                            <input type="time" class="form-control" name="check_in">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['check_out']) ? $lang['check_out'] : 'وقت الخروج'; ?></label>
                            <input type="time" class="form-control" name="check_out">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                            <select class="form-select" name="status">
                                <option value="present"><?php echo isset($lang['present']) ? $lang['present'] : 'حاضر'; ?></option>
                                <option value="absent"><?php echo isset($lang['absent']) ? $lang['absent'] : 'غائب'; ?></option>
                                <option value="late"><?php echo isset($lang['late']) ? $lang['late'] : 'متأخر'; ?></option>
                                <option value="half_day"><?php echo isset($lang['half_day']) ? $lang['half_day'] : 'نصف يوم'; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'ملاحظات'; ?></label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Attendance Modal -->
<?php if ($editAttendance): ?>
<div class="modal fade show" id="editAttendanceModal" tabindex="-1" style="display: block;">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $editAttendance['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?> <?php echo isset($lang['attendance']) ? $lang['attendance'] : 'سجل حضور'; ?></h5>
                    <a href="?page=attendance" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['username']) ? $lang['username'] : 'المستخدم'; ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="user_id" required>
                                <?php 
                                $editUserIdValid = isValidSelectValue($editAttendance['user_id'] ?? 0, $allUsers, 'id');
                                foreach ($allUsers as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo $editUserIdValid && ($editAttendance['user_id'] ?? 0) == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" value="<?php echo $editAttendance['date']; ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['check_in']) ? $lang['check_in'] : 'وقت الدخول'; ?></label>
                            <input type="time" class="form-control" name="check_in" value="<?php echo $editAttendance['check_in'] ? date('H:i', strtotime($editAttendance['check_in'])) : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['check_out']) ? $lang['check_out'] : 'وقت الخروج'; ?></label>
                            <input type="time" class="form-control" name="check_out" value="<?php echo $editAttendance['check_out'] ? date('H:i', strtotime($editAttendance['check_out'])) : ''; ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                            <select class="form-select" name="status">
                                <option value="present" <?php echo $editAttendance['status'] === 'present' ? 'selected' : ''; ?>><?php echo isset($lang['present']) ? $lang['present'] : 'حاضر'; ?></option>
                                <option value="absent" <?php echo $editAttendance['status'] === 'absent' ? 'selected' : ''; ?>><?php echo isset($lang['absent']) ? $lang['absent'] : 'غائب'; ?></option>
                                <option value="late" <?php echo $editAttendance['status'] === 'late' ? 'selected' : ''; ?>><?php echo isset($lang['late']) ? $lang['late'] : 'متأخر'; ?></option>
                                <option value="half_day" <?php echo $editAttendance['status'] === 'half_day' ? 'selected' : ''; ?>><?php echo isset($lang['half_day']) ? $lang['half_day'] : 'نصف يوم'; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'ملاحظات'; ?></label>
                        <textarea class="form-control" name="notes" rows="3"><?php echo htmlspecialchars($editAttendance['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="?page=attendance" class="btn btn-outline"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></a>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?></button>
                </div>
            </form>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
</div>
<?php endif; ?>

<script>
function deleteAttendance(id, name, date) {
    if (confirm('<?php echo isset($lang['confirm_delete']) ? $lang['confirm_delete'] : 'هل أنت متأكد من حذف'; ?> سجل حضور "' + name + '" بتاريخ ' + date + '؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

