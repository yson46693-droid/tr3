<?php
/**
 * صفحة تسجيل الحضور للمندوب
 * Attendance Page for Sales
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

requireRole('sales');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Search and Filter - فقط سجلات المستخدم الحالي
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-t');

// Build query - فقط سجلات المستخدم الحالي
$whereConditions = ["a.user_id = ?"];
$params = [$currentUser['id']];

if (!empty($search)) {
    $whereConditions[] = "(a.date LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
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

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Get total count
$totalCountQuery = "SELECT COUNT(*) as total FROM attendance a 
                    LEFT JOIN users u ON a.user_id = u.id 
                    $whereClause";
$totalCount = $db->queryOne($totalCountQuery, $params)['total'] ?? 0;
$totalPages = ceil($totalCount / $perPage);

// Get attendance records
$attendanceQuery = "SELECT a.*, u.username, u.full_name, u.role 
                    FROM attendance a 
                    LEFT JOIN users u ON a.user_id = u.id 
                    $whereClause
                    ORDER BY a.date DESC, a.check_in DESC 
                    LIMIT ? OFFSET ?";
$queryParams = array_merge($params, [$perPage, $offset]);
$attendanceRecords = $db->query($attendanceQuery, $queryParams);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'check_in') {
        $date = date('Y-m-d');
        $checkInTime = date('H:i:s');
        
        // التحقق من عدم وجود سجل حضور لهذا اليوم
        $existingRecord = $db->queryOne(
            "SELECT id FROM attendance WHERE user_id = ? AND date = ?",
            [$currentUser['id'], $date]
        );
        
        if ($existingRecord) {
            $error = 'تم تسجيل الحضور لهذا اليوم مسبقاً';
        } else {
            try {
                $db->execute(
                    "INSERT INTO attendance (user_id, date, check_in, status) VALUES (?, ?, ?, 'present')",
                    [$currentUser['id'], $date, $checkInTime]
                );
                $success = 'تم تسجيل الحضور بنجاح';
                logAudit($db, $currentUser['id'], 'attendance', 'check_in', "تسجيل حضور: $date $checkInTime");
                
                // إعادة التوجيه لتجنب إعادة الإرسال
                header("Location: " . $_SERVER['PHP_SELF'] . "?page=attendance&success=check_in");
                exit;
            } catch (Exception $e) {
                $error = 'حدث خطأ أثناء تسجيل الحضور: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'check_out') {
        $date = date('Y-m-d');
        $checkOutTime = date('H:i:s');
        
        // البحث عن سجل الحضور لهذا اليوم
        $existingRecord = $db->queryOne(
            "SELECT id, check_in FROM attendance WHERE user_id = ? AND date = ?",
            [$currentUser['id'], $date]
        );
        
        if (!$existingRecord) {
            $error = 'لا يوجد سجل حضور لهذا اليوم';
        } elseif ($existingRecord['check_out']) {
            $error = 'تم تسجيل الخروج مسبقاً';
        } else {
            try {
                $db->execute(
                    "UPDATE attendance SET check_out = ? WHERE id = ?",
                    [$checkOutTime, $existingRecord['id']]
                );
                $success = 'تم تسجيل الخروج بنجاح';
                logAudit($db, $currentUser['id'], 'attendance', 'check_out', "تسجيل خروج: $date $checkOutTime");
                
                // إعادة التوجيه لتجنب إعادة الإرسال
                header("Location: " . $_SERVER['PHP_SELF'] . "?page=attendance&success=check_out");
                exit;
            } catch (Exception $e) {
                $error = 'حدث خطأ أثناء تسجيل الخروج: ' . $e->getMessage();
            }
        }
    }
}

// التحقق من حالة الحضور اليوم
$todayRecord = $db->queryOne(
    "SELECT * FROM attendance WHERE user_id = ? AND date = ?",
    [$currentUser['id'], date('Y-m-d')]
);

// Handle success messages from redirect
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'check_in') {
        $success = 'تم تسجيل الحضور بنجاح';
    } elseif ($_GET['success'] === 'check_out') {
        $success = 'تم تسجيل الخروج بنجاح';
    }
}
?>

<!-- Page Header -->
<div class="page-header mb-4">
    <h2><i class="bi bi-clock-history"></i> تسجيل الحضور والانصراف</h2>
</div>

<!-- Check In/Out Card -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>تسجيل الحضور اليوم</h5>
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
        
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-3">
                    <div>
                        <h6 class="mb-1"><?php echo date('Y-m-d'); ?></h6>
                        <small class="text-muted"><?php echo date('l', strtotime('today')); ?></small>
                    </div>
                    <div class="vr"></div>
                    <div>
                        <h6 class="mb-1" id="currentTime"><?php echo date('H:i:s'); ?></h6>
                        <small class="text-muted">الوقت الحالي</small>
                    </div>
                </div>
            </div>
            <div class="col-md-6 text-end">
                <?php if (!$todayRecord): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="check_in">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-box-arrow-in-right me-2"></i>تسجيل الحضور
                        </button>
                    </form>
                <?php elseif (!$todayRecord['check_out']): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="check_out">
                        <button type="submit" class="btn btn-warning btn-lg">
                            <i class="bi bi-box-arrow-right me-2"></i>تسجيل الخروج
                        </button>
                    </form>
                    <?php if ($todayRecord['check_in']): ?>
                        <div class="mt-2">
                            <small class="text-muted">
                                وقت الدخول: <strong><?php echo date('H:i', strtotime($todayRecord['check_in'])); ?></strong>
                            </small>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong>تم إكمال اليوم</strong>
                        <div class="mt-2">
                            <small>
                                الدخول: <strong><?php echo date('H:i', strtotime($todayRecord['check_in'])); ?></strong> | 
                                الخروج: <strong><?php echo date('H:i', strtotime($todayRecord['check_out'])); ?></strong>
                            </small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Attendance History Card -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>سجل الحضور</h5>
    </div>
    <div class="card-body">
        <!-- Search and Filter -->
        <?php
        require_once __DIR__ . '/../../includes/path_helper.php';
        $currentUrl = getRelativeUrl('dashboard/sales.php');
        ?>
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="mb-4">
            <input type="hidden" name="page" value="attendance">
            <div class="row g-3">
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="بحث بالتاريخ..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value="">جميع الحالات</option>
                        <option value="present" <?php echo $statusFilter === 'present' ? 'selected' : ''; ?>>حاضر</option>
                        <option value="absent" <?php echo $statusFilter === 'absent' ? 'selected' : ''; ?>>غائب</option>
                        <option value="late" <?php echo $statusFilter === 'late' ? 'selected' : ''; ?>>متأخر</option>
                        <option value="half_day" <?php echo $statusFilter === 'half_day' ? 'selected' : ''; ?>>نصف يوم</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-search me-2"></i>بحث
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
                        <th>التاريخ</th>
                        <th>وقت الدخول</th>
                        <th>وقت الخروج</th>
                        <th>إجمالي الساعات</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($attendanceRecords)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="bi bi-inbox me-2"></i>لا توجد بيانات
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRecords as $index => $record): ?>
                            <tr>
                                <td><?php echo $offset + $index + 1; ?></td>
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
                                        <?php 
                                        $statusLabels = [
                                            'present' => 'حاضر',
                                            'absent' => 'غائب',
                                            'late' => 'متأخر',
                                            'half_day' => 'نصف يوم'
                                        ];
                                        echo $statusLabels[$record['status']] ?? $record['status'];
                                        ?>
                                    </span>
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
                    <a class="page-link" href="?page=attendance&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=attendance&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=attendance&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=attendance&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=attendance&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?><?php echo $dateFrom ? '&date_from=' . urlencode($dateFrom) : ''; ?><?php echo $dateTo ? '&date_to=' . urlencode($dateTo) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
// تحديث الوقت الحالي كل ثانية
function updateCurrentTime() {
    const now = new Date();
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const timeElement = document.getElementById('currentTime');
    if (timeElement) {
        timeElement.textContent = hours + ':' + minutes + ':' + seconds;
    }
}

setInterval(updateCurrentTime, 1000);
</script>

