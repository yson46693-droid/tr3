<?php
/**
 * API: تسجيل الحضور والانصراف مع الكاميرا
 */

// إضافة CORS headers للسماح بالوصول
header('Access-Control-Allow-Origin: ' . (isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// معالجة OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance.php';
require_once __DIR__ . '/../includes/audit_log.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح لك'], JSON_UNESCAPED_UNICODE);
    exit;
}

// قراءة البيانات من JSON أو POST
$inputData = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true) ?? [];
} else {
    $inputData = $_POST;
}

$action = $inputData['action'] ?? $_GET['action'] ?? '';
$currentUser = getCurrentUser();
$db = db();

try {
    // التحقق من وجود جدول attendance_records
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    if (empty($tableCheck)) {
        // إنشاء الجدول تلقائياً
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
    }
    $checkoutColumn = $db->queryOne("SHOW COLUMNS FROM attendance_records LIKE 'checkout_photo_path'");
    if (empty($checkoutColumn)) {
        $db->execute("ALTER TABLE attendance_records ADD COLUMN `checkout_photo_path` varchar(255) DEFAULT NULL AFTER `photo_path`");
    }
    
    if ($action === 'check_in') {
        // الحصول على الصورة من البيانات المرسلة
        $photoBase64 = $inputData['photo'] ?? null;
        
        // تسجيل تفاصيل الصورة المستلمة
        if ($photoBase64) {
            error_log("Check-in: Photo received, length=" . strlen($photoBase64) . ", starts with=" . substr($photoBase64, 0, 50));
        } else {
            error_log("Check-in: No photo received. POST keys: " . implode(', ', array_keys($_POST)));
        }
        
        $result = recordAttendanceCheckIn($currentUser['id'], $photoBase64);
        
        if ($result['success']) {
            logAudit($currentUser['id'], 'check_in', 'attendance', $result['record_id'], null, [
                'delay_minutes' => $result['delay_minutes']
            ]);
        }
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'check_out') {
        // الحصول على الصورة من البيانات المرسلة
        $photoBase64 = $inputData['photo'] ?? null;
        
        // تسجيل تفاصيل الصورة المستلمة
        if ($photoBase64) {
            error_log("Check-out: Photo received, length=" . strlen($photoBase64) . ", starts with=" . substr($photoBase64, 0, 50));
        } else {
            error_log("Check-out: No photo received. POST keys: " . implode(', ', array_keys($_POST)));
        }
        
        $result = recordAttendanceCheckOut($currentUser['id'], $photoBase64);
        
        if ($result['success']) {
            logAudit($currentUser['id'], 'check_out', 'attendance', null, null, [
                'work_hours' => $result['work_hours']
            ]);
        }
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'get_statistics') {
        $month = $_GET['month'] ?? date('Y-m');
        $stats = getAttendanceStatistics($currentUser['id'], $month);
        echo json_encode(['success' => true, 'statistics' => $stats], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'get_today_records') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $records = getTodayAttendanceRecords($currentUser['id'], $date);
        echo json_encode(['success' => true, 'records' => $records], JSON_UNESCAPED_UNICODE);
        
    } elseif ($action === 'get_work_time') {
        // الحصول على موعد العمل للمستخدم
        $workTime = getOfficialWorkTime($currentUser['id']);
        if ($workTime) {
            echo json_encode([
                'success' => true,
                'work_time' => $workTime
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'لا يوجد موعد عمل للمستخدم'
            ], JSON_UNESCAPED_UNICODE);
        }
        
    } elseif ($action === 'check_today') {
        // التحقق من أن المستخدم سجل حضور اليوم
        $today = date('Y-m-d');
        $todayRecord = $db->queryOne(
            "SELECT id, check_out_time FROM attendance_records 
             WHERE user_id = ? AND date = ? AND check_in_time IS NOT NULL 
             ORDER BY check_in_time DESC LIMIT 1",
            [$currentUser['id'], $today]
        );

        $checkedOut = false;
        if ($todayRecord && !empty($todayRecord['check_out_time'])) {
            $checkedOut = true;
        }

        echo json_encode([
            'success' => true,
            'checked_in' => !empty($todayRecord),
            'checked_out' => $checkedOut
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'عملية غير صحيحة'], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    error_log("Attendance API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
