<?php
/**
 * صفحة تسجيل الحضور والانصراف المتقدمة مع الكاميرا
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/attendance.php';
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/table_styles.php';

requireLogin();

$currentUser = getCurrentUser();
$db = db();
$today = date('Y-m-d');

// تنظيف صور الحضور والانصراف القديمة (أكثر من 30 يوم) عند فتح الصفحة
// يتم التنظيف مرة واحدة فقط في اليوم لتجنب التأثير على الأداء
$cleanupCacheKey = 'attendance_photos_cleanup_' . $today;
if (!isset($_SESSION[$cleanupCacheKey])) {
    try {
        $cleanupStats = cleanupOldAttendancePhotos(30);
        $_SESSION[$cleanupCacheKey] = true;
        
        // تسجيل عملية التنظيف فقط إذا تم حذف ملفات
        if ($cleanupStats['deleted_files'] > 0) {
            error_log(sprintf(
                'Attendance photos auto-cleanup: %d files deleted, %.2f MB freed',
                $cleanupStats['deleted_files'],
                $cleanupStats['total_size_freed'] / (1024 * 1024)
            ));
        }
    } catch (Exception $e) {
        error_log('Attendance photos cleanup error: ' . $e->getMessage());
    }
}

// التحقق من وجود جدول attendance_records وإنشاؤه إذا لم يكن موجوداً
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
if (empty($tableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `attendance_records` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `date` date NOT NULL,
              `check_in_time` datetime NOT NULL,
              `check_out_time` datetime DEFAULT NULL,
              `delay_minutes` int(11) DEFAULT 0,
              `work_hours` decimal(5,2) DEFAULT 0.00,
              `photo_path` varchar(255) DEFAULT NULL,
              `checkout_photo_path` varchar(255) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `date` (`date`),
              KEY `user_date` (`user_id`, `date`),
              KEY `check_in_time` (`check_in_time`),
              CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating attendance_records table: " . $e->getMessage());
    }
}

$checkoutColumn = $db->queryOne("SHOW COLUMNS FROM attendance_records LIKE 'checkout_photo_path'");
if (empty($checkoutColumn)) {
    try {
        $db->execute("ALTER TABLE attendance_records ADD COLUMN `checkout_photo_path` varchar(255) DEFAULT NULL AFTER `photo_path`");
    } catch (Exception $e) {
        error_log("Error adding checkout_photo_path column: " . $e->getMessage());
    }
}

// التحقق من أن المستخدم ليس مدير (المدير ليس له حضور وانصراف)
if ($currentUser['role'] === 'manager') {
    header('Location: ' . getDashboardUrl('manager'));
    exit;
}

// الحصول على موعد العمل الرسمي
$workTime = getOfficialWorkTime($currentUser['id']);

if (!$workTime) {
    header('Location: ' . getDashboardUrl($currentUser['role']));
    exit;
}

// الحصول على سجلات اليوم
$todayRecords = getTodayAttendanceRecords($currentUser['id'], $today);

// تحديد حالة الأزرار (آخر سجل)
$lastRecord = null;
$canCheckIn = true;  // يمكن تسجيل حضور
$canCheckOut = false; // لا يمكن تسجيل انصراف

if (!empty($todayRecords)) {
    // الحصول على آخر سجل (بترتيب check_in_time DESC)
    $lastRecord = end($todayRecords);
    
    // إذا كان آخر سجل لديه check_in_time ولكن بدون check_out_time
    // يعني المستخدم سجل حضور ولم يسجل انصراف بعد
    if ($lastRecord && !empty($lastRecord['check_in_time']) && empty($lastRecord['check_out_time'])) {
        $canCheckIn = false;  // لا يمكن تسجيل حضور جديد
        $canCheckOut = true;  // يمكن تسجيل انصراف
    } else if ($lastRecord && !empty($lastRecord['check_out_time'])) {
        // آخر سجل هو انصراف كامل، يمكن تسجيل حضور جديد
        $canCheckIn = true;
        $canCheckOut = false;
    }
}

// الحصول على إحصائيات الشهر الحالي
$monthStats = getAttendanceStatistics($currentUser['id'], date('Y-m'));

// حساب الساعات الحالية اليوم
$todayHours = calculateTodayHours($currentUser['id'], $today);

// حساب إحصائيات التأخير الشهرية بالاعتماد على أول تسجيل حضور يومي
$delayStats = calculateMonthlyDelaySummary($currentUser['id'], date('Y-m'));

require_once __DIR__ . '/includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['attendance']) ? $lang['attendance'] : 'الحضور والانصراف';
?>
<?php include __DIR__ . '/templates/header.php'; ?>

<?php
$dashboardUrl = getDashboardUrl($currentUser['role']);
require_once __DIR__ . '/includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>
<div class="page-header mb-4 d-flex justify-content-between align-items-center">
    <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i><?php echo isset($lang['attendance']) ? $lang['attendance'] : 'الحضور والانصراف'; ?></h2>
    <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-back">
        <i class="bi bi-arrow-right me-2"></i><span><?php echo isset($lang['back']) ? $lang['back'] : 'رجوع'; ?></span>
    </a>
</div>

<div class="container-fluid">
    <!-- إحصائيات سريعة -->
    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="stat-card-icon blue">
                                <i class="bi bi-clock-history"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">ساعات اليوم</div>
                            <div class="h4 mb-0"><?php echo $todayHours; ?></div>
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
                                <i class="bi bi-calendar-month"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">ساعات الشهر</div>
                            <div class="h4 mb-0"><?php echo $monthStats['total_hours']; ?></div>
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
                        <div class="h4 mb-0"><?php echo number_format($delayStats['average_minutes'] ?? 0, 2); ?> دقيقة</div>
                        <div class="text-muted small mt-1">إجمالي التأخير: <?php echo number_format($delayStats['total_minutes'] ?? 0, 2); ?> دقيقة</div>
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
                            <div class="stat-card-icon purple">
                                <i class="bi bi-clock"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="text-muted small">مرات التأخير</div>
                            <div class="h4 mb-0"><?php echo (int) ($delayStats['delay_days'] ?? 0); ?></div>
                            <div class="text-muted small mt-1">من إجمالي <?php echo (int) ($delayStats['attendance_days'] ?? 0); ?> أيام حضور</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- معلومات موعد العمل -->
    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle me-2"></i>
        <strong>موعد العمل الرسمي:</strong> من <?php echo date('g:i A', strtotime($workTime['start'])); ?> إلى <?php echo date('g:i A', strtotime($workTime['end'])); ?>
    </div>
    
    <!-- أزرار تسجيل الحضور والانصراف -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-in-right me-2"></i>تسجيل الحضور</h5>
                </div>
                <div class="card-body text-center">
                    <button type="button" class="btn btn-success btn-lg w-100" id="checkInBtn" data-action="check_in" 
                            <?php echo !$canCheckIn ? 'disabled' : ''; ?>>
                        <i class="bi bi-camera me-2"></i>
                        <?php echo $canCheckIn ? 'تسجيل الحضور' : 'تم تسجيل الحضور'; ?>
                    </button>
                    <?php if (!$canCheckIn): ?>
                        <small class="text-warning d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>يجب تسجيل الانصراف أولاً
                        </small>
                    <?php else: ?>
                        <small class="text-muted d-block mt-2">سيتم التقاط صورة تلقائياً</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>تسجيل الانصراف</h5>
                </div>
                <div class="card-body text-center">
                    <button type="button" class="btn btn-danger btn-lg w-100" id="checkOutBtn" data-action="check_out"
                            <?php echo !$canCheckOut ? 'disabled' : ''; ?>>
                        <i class="bi bi-camera me-2"></i>
                        <?php echo $canCheckOut ? 'تسجيل الانصراف' : 'لا يمكن تسجيل الانصراف'; ?>
                    </button>
                    <?php if (!$canCheckOut): ?>
                        <small class="text-warning d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>يجب تسجيل الحضور أولاً
                        </small>
                    <?php else: ?>
                        <small class="text-muted d-block mt-2">سيتم التقاط صورة تلقائياً</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- سجلات اليوم -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-calendar3 me-2"></i>سجلات اليوم (<?php echo formatDate($today); ?>)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($todayRecords)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    لا توجد سجلات حضور اليوم
                </div>
            <?php else: ?>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>تسجيل الحضور</th>
                                <th>تسجيل الانصراف</th>
                                <th>التأخير</th>
                                <th>ساعات العمل</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayRecords as $index => $record): ?>
                                <tr>
                                    <td data-label="#"><?php echo $index + 1; ?></td>
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
                                        <?php echo isset($record['work_hours']) && $record['work_hours'] > 0 ? number_format($record['work_hours'], 2) . ' ساعة' : '-'; ?>
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
                        </tbody>
                        <tfoot>
                            <tr class="table-info">
                                <td colspan="4" class="text-end"><strong>إجمالي ساعات اليوم:</strong></td>
                                <td colspan="2"><strong><?php echo $todayHours; ?> ساعة</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- سجل الحضور للشهر -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-calendar-range me-2"></i>سجل الحضور - الشهر الحالي</h5>
            <?php if (hasRole('accountant')): ?>
            <a href="<?php echo getDashboardUrl($currentUser['role']); ?>?page=attendance_management" class="btn btn-light btn-sm">
                <i class="bi bi-bar-chart me-2"></i>متابعة الحضور
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>عدد التسجيلات</th>
                            <th>ساعات العمل</th>
                            <th>متوسط التأخير</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // الحصول على سجلات الشهر مجمعة حسب التاريخ
                        $monthRecords = $db->query(
                            "SELECT date, 
                                    COUNT(*) as records_count,
                                    COALESCE(SUM(work_hours), 0) as total_hours,
                                    MIN(check_in_time) as first_check_in,
                                    MAX(check_out_time) as last_check_out
                             FROM attendance_records 
                             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
                             GROUP BY date
                             ORDER BY date DESC",
                            [$currentUser['id'], date('Y-m')]
                        );
                        ?>
                        <?php if (empty($monthRecords)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">لا توجد سجلات لهذا الشهر</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $dailyDelayDetails = $delayStats['details'] ?? [];
                            ?>
                            <?php foreach ($monthRecords as $record): ?>
                                <?php
                                $dayDelay = isset($dailyDelayDetails[$record['date']]['delay'])
                                    ? (float) $dailyDelayDetails[$record['date']]['delay']
                                    : 0.0;
                                ?>
                                <tr>
                                    <td data-label="التاريخ"><?php echo formatDate($record['date']); ?></td>
                                    <td data-label="عدد التسجيلات">
                                        <span class="badge bg-info"><?php echo $record['records_count']; ?></span>
                                    </td>
                                    <td data-label="ساعات العمل">
                                        <strong><?php echo number_format($record['total_hours'] ?? 0, 2); ?> ساعة</strong>
                                    </td>
                                    <td data-label="التأخير">
                                        <?php if ($dayDelay > 0): ?>
                                            <span class="badge bg-warning"><?php echo number_format($dayDelay, 2); ?> دقيقة</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">في الوقت</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="الحالة">
                                        <span class="badge bg-success">حاضر</span>
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

<!-- Modal الكاميرا -->
<div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cameraModalTitle">التقاط صورة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="cameraContainer" class="text-center">
                    <div id="cameraLoading" class="text-center mb-3" style="display: none;">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">جاري تحميل الكاميرا...</span>
                        </div>
                        <p class="mt-2 text-muted">جاري تحميل الكاميرا...</p>
                    </div>
                    <video id="video" autoplay playsinline muted style="width: 100%; max-width: 500px; border-radius: 8px; background: #000; min-height: 300px;"></video>
                    <canvas id="canvas" style="display: none;"></canvas>
                    <div id="cameraError" class="alert alert-danger" style="display: none;">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <span id="cameraErrorText">خطأ في الكاميرا</span>
                    </div>
                </div>
                <div id="capturedImageContainer" style="display: none; text-align: center;">
                    <img id="capturedImage" src="" alt="الصورة الملتقطة" style="max-width: 100%; border-radius: 8px;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="cancelBtn">إلغاء</button>
                <button type="button" class="btn btn-primary" id="captureBtn" style="display: none;">
                    <i class="bi bi-camera me-2"></i>التقاط
                </button>
                <button type="button" class="btn btn-success" id="retakeBtn" style="display: none;">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>إعادة التقاط
                </button>
                <button type="button" class="btn btn-primary" id="submitBtn" style="display: none;">
                    <i class="bi bi-check-circle me-2"></i>تأكيد وإرسال
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // تمرير بيانات المستخدم للـ JavaScript
    window.currentUser = {
        id: <?php echo $currentUser['id']; ?>,
        role: '<?php echo htmlspecialchars($currentUser['role']); ?>'
    };
</script>
<script src="<?php echo ASSETS_URL; ?>js/attendance.js"></script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>

<?php include __DIR__ . '/templates/footer.php'; ?>
