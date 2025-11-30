<?php
/**
 * صفحة متابعة الحضور والانصراف مع الإحصائيات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();

$error = '';
$success = '';

// معالجة POST لتحديث العدادات والصلاحيات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole(['accountant', 'manager']);
    
    $action = $_POST['action'] ?? '';
    $targetUserId = intval($_POST['user_id'] ?? 0);
    
    if ($targetUserId <= 0) {
        $error = 'معرف مستخدم غير صحيح';
    } else {
        // التحقق من أن المستخدم المستهدف ليس مديراً
        $targetUser = $db->queryOne("SELECT id, role FROM users WHERE id = ? AND role != 'manager'", [$targetUserId]);
        if (!$targetUser) {
            $error = 'المستخدم غير موجود أو لا يمكن تعديل بياناته';
        } else {
            if ($action === 'update_counts') {
                // تحديث عداد التأخيرات وعداد الإنذارات
                $delayCount = max(0, intval($_POST['delay_count'] ?? 0));
                $warningCount = max(0, intval($_POST['warning_count'] ?? 0));
                
                try {
                    $db->execute(
                        "UPDATE users SET delay_count = ?, warning_count = ? WHERE id = ?",
                        [$delayCount, $warningCount, $targetUserId]
                    );
                    $success = 'تم تحديث العدادات بنجاح';
                } catch (Exception $e) {
                    $error = 'حدث خطأ أثناء تحديث العدادات: ' . $e->getMessage();
                }
            } elseif ($action === 'update_permissions') {
                // تحديث صلاحيات الحضور والانصراف
                require_once __DIR__ . '/../../includes/audit_log.php';
                
                // التأكد من وجود الحقول
                $canCheckInColumn = $db->queryOne("SHOW COLUMNS FROM users LIKE 'can_check_in'");
                if (empty($canCheckInColumn)) {
                    try {
                        $db->execute("ALTER TABLE users ADD COLUMN `can_check_in` tinyint(1) DEFAULT 1 AFTER `warning_count`");
                    } catch (Exception $e) {
                        error_log("Error adding can_check_in column: " . $e->getMessage());
                    }
                }
                
                $canCheckOutColumn = $db->queryOne("SHOW COLUMNS FROM users LIKE 'can_check_out'");
                if (empty($canCheckOutColumn)) {
                    try {
                        $db->execute("ALTER TABLE users ADD COLUMN `can_check_out` tinyint(1) DEFAULT 1 AFTER `can_check_in`");
                    } catch (Exception $e) {
                        error_log("Error adding can_check_out column: " . $e->getMessage());
                    }
                }
                
                $canCheckIn = isset($_POST['can_check_in']) ? 1 : 0;
                $canCheckOut = isset($_POST['can_check_out']) ? 1 : 0;
                
                try {
                    $db->execute(
                        "UPDATE users SET can_check_in = ?, can_check_out = ? WHERE id = ?",
                        [$canCheckIn, $canCheckOut, $targetUserId]
                    );
                    
                    logAudit($currentUser['id'], 'update_user_attendance_permissions', 'users', $targetUserId, null, [
                        'can_check_in' => $canCheckIn,
                        'can_check_out' => $canCheckOut
                    ]);
                    
                    $success = 'تم تحديث الصلاحيات بنجاح';
                } catch (Exception $e) {
                    $error = 'حدث خطأ أثناء تحديث الصلاحيات: ' . $e->getMessage();
                }
            }
        }
    }
    
    // إعادة توجيه مع الرسائل
    $redirectUrl = '?page=attendance_management&month=' . urlencode($_GET['month'] ?? date('Y-m'));
    if ($targetUserId > 0) {
        $redirectUrl .= '&user_id=' . $targetUserId;
    }
    if ($success) {
        $redirectUrl .= '&success=' . urlencode($success);
    }
    if ($error) {
        $redirectUrl .= '&error=' . urlencode($error);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// عرض رسائل النجاح والخطأ من GET
if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// الفلترة
$selectedMonth = $_GET['month'] ?? date('Y-m');
$selectedUserId = $_GET['user_id'] ?? 0;
$selectedDate = $_GET['date'] ?? '';

// الحصول على قائمة المستخدمين (استبعاد المديرين)
$users = $db->query(
    "SELECT id, username, full_name, role FROM users WHERE status = 'active' AND role != 'manager' ORDER BY full_name ASC"
);

// التأكد من وجود الأعمدة الجديدة
ensureDelayCountColumn();
ensureWarningCountColumn();

// الحصول على إحصائيات المستخدمين
$userStats = [];
foreach ($users as $user) {
    $stats = getAttendanceStatistics($user['id'], $selectedMonth);
    $delayStats = calculateMonthlyDelaySummary($user['id'], $selectedMonth);
    
    // الحصول على عداد التأخيرات وعداد الإنذارات من جدول users
    $userCounts = $db->queryOne(
        "SELECT delay_count, warning_count FROM users WHERE id = ?",
        [$user['id']]
    );
    
    $userStats[$user['id']] = [
        'user' => $user,
        'stats' => $stats,
        'delay' => $delayStats,
        'delay_count' => (int)($userCounts['delay_count'] ?? 0),
        'warning_count' => (int)($userCounts['warning_count'] ?? 0)
    ];
}

// إذا تم اختيار مستخدم محدد
$selectedUserStats = null;
$selectedUserRecords = [];
if ($selectedUserId > 0) {
    $selectedUser = $db->queryOne("SELECT * FROM users WHERE id = ? AND role != 'manager'", [$selectedUserId]);
    if ($selectedUser) {
        $selectedUserStats = getAttendanceStatistics($selectedUserId, $selectedMonth);
        $selectedUserDelay = calculateMonthlyDelaySummary($selectedUserId, $selectedMonth);
        
        // الحصول على سجلات المستخدم
        if ($selectedDate) {
            $selectedUserRecords = getTodayAttendanceRecords($selectedUserId, $selectedDate);
        } else {
            // التحقق من وجود جدول attendance_records أولاً
            $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
            if (!empty($tableCheck)) {
                // الحصول على جميع السجلات للشهر من attendance_records
                $selectedUserRecords = $db->query(
                    "SELECT * FROM attendance_records 
                     WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                     ORDER BY date DESC, check_in_time DESC",
                    [$selectedUserId, $selectedMonth]
                );
            } else {
                // استخدام جدول attendance العادي
                $selectedUserRecords = $db->query(
                    "SELECT * FROM attendance 
                     WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                     ORDER BY date DESC, check_in DESC",
                    [$selectedUserId, $selectedMonth]
                );
            }
        }
    }
}

// الحصول على رابط الصفحة الحالية للرجوع (بدون user_id للعودة للقائمة العامة)
$backUrl = '?page=attendance_management&month=' . urlencode($selectedMonth);
?>
<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-calendar-check me-2"></i>متابعة الحضور والانصراف</h2>
    <?php if ($selectedUserId > 0 && $selectedUserStats): ?>
        <a href="<?php echo htmlspecialchars($backUrl); ?>" class="btn btn-back">
            <i class="bi bi-arrow-right me-2"></i><span>رجوع</span>
        </a>
    <?php endif; ?>
</div>

<!-- فلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">الشهر</label>
                <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-4">
                <label class="form-label">المستخدم</label>
                <select class="form-select" name="user_id" onchange="this.form.submit()">
                    <option value="0">جميع المستخدمين</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?> (<?php echo $user['role']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($selectedUserId > 0): ?>
            <div class="col-md-4">
                <label class="form-label">التاريخ (اختياري)</label>
                <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="this.form.submit()">
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($selectedUserId > 0 && $selectedUserStats): ?>
    <!-- إحصائيات المستخدم المحدد -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">أيام الحضور</div>
                            <div class="h4 mb-0"><?php echo $selectedUserStats['present_days']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-card-icon green">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">ساعات العمل</div>
                            <div class="h4 mb-0"><?php echo $selectedUserStats['total_hours']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-card-icon orange">
                                <i class="bi bi-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">متوسط التأخير</div>
                            <div class="h4 mb-0"><?php echo number_format($selectedUserDelay['average_minutes'] ?? 0, 2); ?> دقيقة</div>
                            <div class="text-muted small mt-1">إجمالي التأخير: <?php echo number_format($selectedUserDelay['total_minutes'] ?? 0, 2); ?> دقيقة</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-card-icon red">
                                <i class="bi bi-clock"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">مرات التأخير</div>
                            <div class="h4 mb-0"><?php echo (int) ($selectedUserDelay['delay_days'] ?? 0); ?></div>
                            <div class="text-muted small mt-1">من إجمالي <?php echo (int) ($selectedUserDelay['attendance_days'] ?? 0); ?> أيام حضور</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- إحصائيات إضافية: عداد التأخيرات وعداد الإنذارات -->
    <?php
    $selectedUserCounts = $db->queryOne(
        "SELECT delay_count, warning_count FROM users WHERE id = ?",
        [$selectedUserId]
    );
    $selectedDelayCount = (int)($selectedUserCounts['delay_count'] ?? 0);
    $selectedWarningCount = (int)($selectedUserCounts['warning_count'] ?? 0);
    ?>
    <div class="row mb-4">
        <div class="col-md-6 col-sm-6 mb-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center flex-grow-1">
                            <div class="flex-shrink-0">
                                <div class="stat-card-icon orange">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="text-muted small">عداد تأخيرات الحضور الشهري</div>
                                <div class="h4 mb-0 <?php echo $selectedDelayCount >= 3 ? 'text-danger' : ''; ?>">
                                    <?php echo $selectedDelayCount; ?>
                                </div>
                                <?php if ($selectedDelayCount >= 3): ?>
                                    <div class="text-danger small mt-1">
                                        <i class="bi bi-exclamation-circle"></i> تم إبلاغ المدير
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (in_array($currentUser['role'], ['manager', 'accountant'])): ?>
                            <button class="btn btn-sm btn-outline-warning" onclick="editCounts(<?php echo $selectedUserId; ?>, <?php echo $selectedDelayCount; ?>, <?php echo $selectedWarningCount; ?>)" title="تعديل العدادات">
                                <i class="bi bi-pencil"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-sm-6 mb-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-card-icon red">
                                <i class="bi bi-bell-fill"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">عداد إنذارات نسيان الانصراف</div>
                            <div class="h4 mb-0 <?php echo $selectedWarningCount >= 3 ? 'text-danger' : ''; ?>">
                                <?php echo $selectedWarningCount; ?>
                            </div>
                            <?php if ($selectedWarningCount >= 3): ?>
                                <div class="text-danger small mt-1">
                                    <i class="bi bi-exclamation-circle"></i> تم خصم ساعتين إضافيتين
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- قسم الصلاحيات -->
    <?php
    // الحصول على صلاحيات المستخدم
    $userPermissions = $db->queryOne(
        "SELECT can_check_in, can_check_out FROM users WHERE id = ?",
        [$selectedUserId]
    );
    
    // التأكد من وجود الحقول
    $canCheckInColumn = $db->queryOne("SHOW COLUMNS FROM users LIKE 'can_check_in'");
    $canCheckOutColumn = $db->queryOne("SHOW COLUMNS FROM users LIKE 'can_check_out'");
    
    if (empty($canCheckInColumn) || empty($canCheckOutColumn)) {
        // الحقول غير موجودة، القيم الافتراضية
        $canCheckIn = 1;
        $canCheckOut = 1;
    } else {
        $canCheckIn = (int)($userPermissions['can_check_in'] ?? 1);
        $canCheckOut = (int)($userPermissions['can_check_out'] ?? 1);
    }
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-info">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>صلاحيات الحضور والانصراف</h6>
                    <?php if (in_array($currentUser['role'], ['manager', 'accountant'])): ?>
                        <button class="btn btn-sm btn-light" onclick="editPermissions(<?php echo $selectedUserId; ?>, <?php echo $canCheckIn; ?>, <?php echo $canCheckOut; ?>)" title="تعديل الصلاحيات">
                            <i class="bi bi-pencil"></i> تعديل
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <?php if ($canCheckIn): ?>
                                        <i class="bi bi-check-circle-fill text-success" style="font-size: 1.5rem;"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger" style="font-size: 1.5rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <strong>تسجيل الحضور:</strong>
                                    <span class="badge <?php echo $canCheckIn ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo $canCheckIn ? 'مسموح' : 'محظور'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <?php if ($canCheckOut): ?>
                                        <i class="bi bi-check-circle-fill text-success" style="font-size: 1.5rem;"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle-fill text-danger" style="font-size: 1.5rem;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <strong>تسجيل الانصراف:</strong>
                                    <span class="badge <?php echo $canCheckOut ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo $canCheckOut ? 'مسموح' : 'محظور'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- سجلات المستخدم -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">سجلات الحضور - <?php echo htmlspecialchars($selectedUser['full_name'] ?? $selectedUser['username']); ?></h5>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>تسجيل الحضور</th>
                            <th>تسجيل الانصراف</th>
                            <th>التأخير</th>
                            <th>ساعات العمل</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($selectedUserRecords)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">لا توجد سجلات</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($selectedUserRecords as $record): ?>
                                <tr>
                                    <td data-label="التاريخ"><?php echo formatDate($record['date']); ?></td>
                                    <td data-label="تسجيل الحضور">
                                        <strong><?php echo formatDateTime($record['check_in_time']); ?></strong>
                                    </td>
                                    <td data-label="تسجيل الانصراف">
                                        <?php echo $record['check_out_time'] ? formatDateTime($record['check_out_time']) : '<span class="text-muted">لم يتم التسجيل</span>'; ?>
                                    </td>
                                    <td data-label="التأخير">
                                        <?php if ($record['delay_minutes'] > 0): ?>
                                            <span class="badge bg-warning"><?php echo $record['delay_minutes']; ?> دقيقة</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">في الوقت</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="ساعات العمل">
                                        <?php echo isset($record['work_hours']) && $record['work_hours'] > 0 ? formatHours($record['work_hours']) : '-'; ?>
                                    </td>
                                    <td data-label="الحالة">
                                        <?php if ($record['check_out_time']): ?>
                                            <span class="badge bg-success">مكتمل</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">قيد العمل</span>
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
    
<?php else: ?>
    <!-- جدول إحصائيات جميع المستخدمين -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">إحصائيات الحضور - <?php echo date('F Y', strtotime($selectedMonth . '-01')); ?></h5>
        </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th>المستخدم</th>
                            <th>الدور</th>
                            <th>أيام الحضور</th>
                            <th>ساعات العمل</th>
                            <th>متوسط التأخير</th>
                            <th>مرات التأخير</th>
                            <th>عداد التأخيرات</th>
                            <th>عداد الإنذارات</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($userStats)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">لا توجد بيانات</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($userStats as $userId => $data): ?>
                                <tr>
                                    <td data-label="المستخدم">
                                        <strong><?php echo htmlspecialchars($data['user']['full_name'] ?? $data['user']['username']); ?></strong>
                                    </td>
                                    <td data-label="الدور">
                                        <span class="badge bg-info"><?php echo $data['user']['role']; ?></span>
                                    </td>
                                    <td data-label="أيام الحضور">
                                        <strong><?php echo $data['stats']['present_days']; ?></strong>
                                    </td>
                                    <td data-label="ساعات العمل">
                                        <strong class="text-success"><?php echo formatHours($data['stats']['total_hours']); ?></strong>
                                    </td>
                                    <td data-label="متوسط التأخير">
                                        <?php if (($data['delay']['average_minutes'] ?? 0) > 0): ?>
                                            <span class="badge bg-warning"><?php echo number_format($data['delay']['average_minutes'], 2); ?> دقيقة</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">في الوقت</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="مرات التأخير">
                                        <span class="badge bg-danger"><?php echo (int) ($data['delay']['delay_days'] ?? 0); ?></span>
                                    </td>
                                    <td data-label="عداد التأخيرات">
                                        <?php 
                                        $delayCount = $data['delay_count'] ?? 0;
                                        $delayBadgeClass = $delayCount >= 3 ? 'bg-danger' : ($delayCount > 0 ? 'bg-warning' : 'bg-secondary');
                                        ?>
                                        <span class="badge <?php echo $delayBadgeClass; ?>" title="عدد حالات التأخير في الحضور لهذا الشهر">
                                            <?php echo $delayCount; ?>
                                        </span>
                                        <?php if ($delayCount >= 3): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-danger" title="تم إبلاغ المدير"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="عداد الإنذارات">
                                        <?php 
                                        $warningCount = $data['warning_count'] ?? 0;
                                        $warningBadgeClass = $warningCount >= 3 ? 'bg-danger' : ($warningCount > 0 ? 'bg-warning' : 'bg-secondary');
                                        ?>
                                        <span class="badge <?php echo $warningBadgeClass; ?>" title="عدد إنذارات نسيان تسجيل الانصراف لهذا الشهر">
                                            <?php echo $warningCount; ?>
                                        </span>
                                        <?php if ($warningCount >= 3): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-danger" title="تم خصم ساعتين إضافيتين"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="الإجراءات">
                                        <a href="?page=attendance_management&month=<?php echo urlencode($selectedMonth); ?>&user_id=<?php echo $userId; ?>" class="btn btn-sm btn-info">
                                            <i class="bi bi-eye"></i> عرض التفاصيل
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($selectedUserId > 0 && in_array($currentUser['role'], ['manager', 'accountant'])): ?>
<!-- Modal تعديل العدادات -->
<div class="modal fade" id="editCountsModal" tabindex="-1" aria-labelledby="editCountsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCountsModalLabel">
                    <i class="bi bi-pencil me-2"></i>تعديل العدادات
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editCountsForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_counts">
                    <input type="hidden" name="user_id" id="countsUserId">
                    
                    <div class="mb-3">
                        <label for="delay_count" class="form-label">عداد تأخيرات الحضور الشهري</label>
                        <input type="number" class="form-control" id="delay_count" name="delay_count" min="0" required>
                        <small class="text-muted">عدد حالات التأخير في الحضور لهذا الشهر</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="warning_count" class="form-label">عداد إنذارات نسيان الانصراف</label>
                        <input type="number" class="form-control" id="warning_count" name="warning_count" min="0" required>
                        <small class="text-muted">عدد إنذارات نسيان تسجيل الانصراف لهذا الشهر</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل الصلاحيات -->
<div class="modal fade" id="editPermissionsModal" tabindex="-1" aria-labelledby="editPermissionsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editPermissionsModalLabel">
                    <i class="bi bi-shield-check me-2"></i>تعديل الصلاحيات
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editPermissionsForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_permissions">
                    <input type="hidden" name="user_id" id="permissionsUserId">
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="can_check_in" name="can_check_in" value="1" checked>
                            <label class="form-check-label" for="can_check_in">
                                <strong>تسجيل الحضور</strong>
                            </label>
                        </div>
                        <small class="text-muted d-block ms-4">السماح للمستخدم بتسجيل الحضور</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="can_check_out" name="can_check_out" value="1" checked>
                            <label class="form-check-label" for="can_check_out">
                                <strong>تسجيل الانصراف</strong>
                            </label>
                        </div>
                        <small class="text-muted d-block ms-4">السماح للمستخدم بتسجيل الانصراف</small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        عند إلغاء تفعيل أي صلاحية، لن يتمكن المستخدم من استخدامها حتى يتم إعادة تفعيلها.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-2"></i>حفظ التغييرات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCounts(userId, delayCount, warningCount) {
    document.getElementById('countsUserId').value = userId;
    document.getElementById('delay_count').value = delayCount;
    document.getElementById('warning_count').value = warningCount;
    
    // إضافة معاملات GET للنموذج
    const form = document.getElementById('editCountsForm');
    const month = new URLSearchParams(window.location.search).get('month') || '<?php echo date('Y-m'); ?>';
    const actionInput = form.querySelector('input[name="action"]');
    const userIdInput = form.querySelector('input[name="user_id"]');
    
    // إضافة hidden inputs للاحتفاظ بالمعاملات عند الإرسال
    let monthInput = form.querySelector('input[name="month"]');
    if (!monthInput) {
        monthInput = document.createElement('input');
        monthInput.type = 'hidden';
        monthInput.name = 'month';
        form.appendChild(monthInput);
    }
    monthInput.value = month;
    
    // إضافة user_id للـ GET parameters عند الإعادة التوجيه
    form.action = window.location.pathname + '?page=attendance_management&month=' + encodeURIComponent(month) + '&user_id=' + userId;
    
    const modal = new bootstrap.Modal(document.getElementById('editCountsModal'));
    modal.show();
}

function editPermissions(userId, canCheckIn, canCheckOut) {
    document.getElementById('permissionsUserId').value = userId;
    document.getElementById('can_check_in').checked = canCheckIn == 1;
    document.getElementById('can_check_out').checked = canCheckOut == 1;
    
    // إضافة معاملات GET للنموذج
    const form = document.getElementById('editPermissionsForm');
    const month = new URLSearchParams(window.location.search).get('month') || '<?php echo date('Y-m'); ?>';
    
    // إضافة hidden inputs للاحتفاظ بالمعاملات عند الإرسال
    let monthInput = form.querySelector('input[name="month"]');
    if (!monthInput) {
        monthInput = document.createElement('input');
        monthInput.type = 'hidden';
        monthInput.name = 'month';
        form.appendChild(monthInput);
    }
    monthInput.value = month;
    
    // إضافة user_id للـ GET parameters عند الإعادة التوجيه
    form.action = window.location.pathname + '?page=attendance_management&month=' + encodeURIComponent(month) + '&user_id=' + userId;
    
    const modal = new bootstrap.Modal(document.getElementById('editPermissionsModal'));
    modal.show();
}
</script>
<?php endif; ?>

