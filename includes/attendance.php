<?php
function formatRoleName($role) {
    $roleNames = [
        'manager' => 'المدير',
        'accountant' => 'المحاسب',
        'sales' => 'مندوب المبيعات',
        'production' => 'عامل الإنتاج',
    ];
    return $roleNames[$role] ?? $role;
}

function formatArabicDate($dateTime) {
    try {
        $dt = new DateTime($dateTime);
    } catch (Exception $e) {
        $dt = new DateTime();
    }
    return $dt->format('Y-m-d');
}

function formatArabicTime($dateTime) {
    try {
        $dt = new DateTime($dateTime);
    } catch (Exception $e) {
        $dt = new DateTime();
    }
    return $dt->format('H:i:s');
}
/**
 * نظام الحضور والانصراف المتقدم
 * Advanced Attendance System
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/simple_telegram.php';
require_once __DIR__ . '/salary_calculator.php';

/**
 * الحصول على موعد العمل الرسمي للمستخدم
 * المدير ليس له حضور وانصراف
 */
function getOfficialWorkTime($userId) {
    $db = db();
    $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
    
    if (!$user) {
        return ['start' => '09:00:00', 'end' => '19:00:00'];
    }
    
    $role = $user['role'];
    
    // المدير ليس له حضور وانصراف
    if ($role === 'manager') {
        return null; // لا يوجد موعد عمل للمدير
    }
    
    // مواعيد العمل الرسمية
    if ($role === 'accountant') {
        return ['start' => '10:00:00', 'end' => '19:00:00'];
    } else {
        // عمال الإنتاج والمندوبين
        return ['start' => '09:00:00', 'end' => '19:00:00'];
    }
}

/**
 * حساب التأخير بالدقائق
 */
function calculateDelay($checkInTime, $officialStartTime) {
    $checkIn = strtotime($checkInTime);
    $official = strtotime($officialStartTime);
    
    if ($checkIn > $official) {
        return round(($checkIn - $official) / 60); // دقائق
    }
    
    return 0;
}

/**
 * حساب ساعات العمل بين وقتين
 */
function calculateWorkHours($checkInTime, $checkOutTime) {
    if (empty($checkInTime) || empty($checkOutTime)) {
        return 0;
    }
    
    $checkIn = strtotime($checkInTime);
    $checkOut = strtotime($checkOutTime);
    
    if ($checkOut > $checkIn) {
        return round(($checkOut - $checkIn) / 3600, 2); // ساعات
    }
    
    return 0;
}

/**
 * حفظ صورة الحضور/الانصراف على الخادم وإرجاع المسارات المطلوبة
 */
function saveAttendancePhoto($photoBase64, $userId, $type = 'checkin') {
    $photoBase64 = is_string($photoBase64) ? trim($photoBase64) : '';
    if ($photoBase64 === '') {
        return [null, null];
    }

    // إزالة أي prefix للـ Base64 مثل data:image/jpeg;base64,
    $cleanData = preg_replace('#^data:image/\w+;base64,#i', '', $photoBase64);
    $cleanData = str_replace(' ', '+', $cleanData);

    // التأكد من أن طول السلسلة قابل للقسمة على 4 كما يتطلب Base64
    $mod = strlen($cleanData) % 4;
    if ($mod > 0) {
        $cleanData .= str_repeat('=', 4 - $mod);
    }

    $imageData = base64_decode($cleanData, true);
    if ($imageData === false) {
        error_log("Attendance photo decode failed for user {$userId} ({$type})");
        return [null, null];
    }

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = __DIR__ . '/../uploads';
    }

    $attendanceDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'attendance';
    if (!is_dir($attendanceDir)) {
        if (!@mkdir($attendanceDir, 0755, true)) {
            error_log("Unable to create attendance photos directory: {$attendanceDir}");
            return [null, null];
        }
    }

    $monthFolder = date('Y-m');
    $targetDir = $attendanceDir . DIRECTORY_SEPARATOR . $monthFolder;
    if (!is_dir($targetDir)) {
        if (!@mkdir($targetDir, 0755, true)) {
            error_log("Unable to create month attendance directory: {$targetDir}");
            return [null, null];
        }
    }

    if (!is_writable($targetDir)) {
        error_log("Attendance directory not writable: {$targetDir}");
        return [null, null];
    }

    try {
        $randomSuffix = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $randomSuffix = uniqid();
    }

    $fileName = sprintf('%s_%d_%s_%s.jpg', $type, $userId, date('Ymd_His'), $randomSuffix);
    $absolutePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    $bytesWritten = @file_put_contents($absolutePath, $imageData, LOCK_EX);
    if ($bytesWritten === false || $bytesWritten === 0) {
        error_log("Failed to save attendance photo: {$absolutePath}");
        return [null, null];
    }

    $relativePath = 'attendance/' . $monthFolder . '/' . $fileName;

    return [$absolutePath, $relativePath];
}

/**
 * الحصول على المسار الكامل للصورة المخزنة انطلاقاً من المسار النسبي
 */
function getAttendancePhotoAbsolutePath($relativePath) {
    if (!$relativePath) {
        return null;
    }

    $relativePath = ltrim(str_replace(['\\', '..'], ['/', ''], $relativePath), '/');
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = __DIR__ . '/../uploads';
    }

    $fullPath = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $realFullPath = realpath($fullPath);

    if ($realFullPath === false) {
        return null;
    }

    if (strpos($realFullPath, $uploadsRoot) !== 0) {
        return null;
    }

    return $realFullPath;
}

/**
 * التأكد من وجود جدول سجلات إشعارات الحضور/الانصراف لمنع التكرار
 */
function ensureAttendanceEventNotificationLogTable(): void
{
    static $tableEnsured = false;

    if ($tableEnsured) {
        return;
    }

    try {
        $db = db();
        $db->execute("
            CREATE TABLE IF NOT EXISTS `attendance_event_notification_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `attendance_record_id` int(11) DEFAULT NULL,
              `event_type` enum('checkin','checkout') NOT NULL,
              `sent_date` date NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `unique_user_event_date` (`user_id`,`event_type`,`sent_date`),
              KEY `attendance_record_idx` (`attendance_record_id`),
              CONSTRAINT `attendance_event_log_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `attendance_event_log_record_fk` FOREIGN KEY (`attendance_record_id`) REFERENCES `attendance_records` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $tableEnsured = true;
    } catch (Exception $e) {
        error_log('Failed to ensure attendance event notification log table: ' . $e->getMessage());
    }
}

/**
 * التحقق من إرسال إشعار الحضور/الانصراف للمستخدم في يوم معين
 */
function hasAttendanceEventNotificationBeenSent(int $userId, string $eventType, string $sentDate): bool
{
    if (!in_array($eventType, ['checkin', 'checkout'], true)) {
        return false;
    }

    ensureAttendanceEventNotificationLogTable();

    try {
        $db = db();
        $row = $db->queryOne(
            "SELECT id FROM attendance_event_notification_logs WHERE user_id = ? AND event_type = ? AND sent_date = ? LIMIT 1",
            [$userId, $eventType, $sentDate]
        );

        return !empty($row);
    } catch (Exception $e) {
        error_log('Failed to check attendance event notification log: ' . $e->getMessage());
        return false;
    }
}

/**
 * تسجيل إرسال إشعار الحضور/الانصراف للمستخدم
 */
function markAttendanceEventNotificationSent(int $userId, string $eventType, string $sentDate, ?int $attendanceRecordId = null): void
{
    if (!in_array($eventType, ['checkin', 'checkout'], true)) {
        return;
    }

    ensureAttendanceEventNotificationLogTable();

    try {
        $db = db();
        $db->execute(
            "INSERT INTO attendance_event_notification_logs (user_id, attendance_record_id, event_type, sent_date)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE attendance_record_id = VALUES(attendance_record_id), updated_at = CURRENT_TIMESTAMP",
            [$userId, $attendanceRecordId, $eventType, $sentDate]
        );
    } catch (Exception $e) {
        error_log('Failed to mark attendance event notification sent: ' . $e->getMessage());
    }
}

/**
 * تسجيل حضور مع صورة
 */
function recordAttendanceCheckIn($userId, $photoBase64 = null) {
    $db = db();
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    
    // الحصول على موعد العمل الرسمي
    $workTime = getOfficialWorkTime($userId);
    $officialStart = $today . ' ' . $workTime['start'];
    
    // حساب التأخير
    $delayMinutes = calculateDelay($now, $officialStart);
    
    // إدراج تسجيل حضور جديد
    $savedPhotoAbsolute = null;
    $savedPhotoRelative = null;

    if ($photoBase64 && !empty(trim($photoBase64))) {
        [$savedPhotoAbsolute, $savedPhotoRelative] = saveAttendancePhoto($photoBase64, $userId, 'checkin');
    }

    $storedPhotoValue = $savedPhotoRelative ?? ($photoBase64 ? 'captured' : null);

    $result = $db->execute(
        "INSERT INTO attendance_records (user_id, date, check_in_time, delay_minutes, photo_path, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$userId, $today, $now, $delayMinutes, $storedPhotoValue]
    );
    
    $recordId = $result['insert_id'];
    
    // الحصول على معلومات المستخدم
    $user = $db->queryOne("SELECT username, full_name, role FROM users WHERE id = ?", [$userId]);
    $userName = $user['full_name'] ?? $user['username'];
    $role = $user['role'] ?? 'unknown';
    
    // إرسال إشعار واحد فقط عبر Telegram (صورة مع جميع البيانات) مع منع التكرار
    $photoDeleted = false;
    $telegramEnabled = isTelegramConfigured();

    if ($telegramEnabled) {
        $alreadySent = hasAttendanceEventNotificationBeenSent($userId, 'checkin', $today);

        if ($alreadySent) {
            error_log("Skipping duplicate attendance check-in notification for user {$userId} on {$today}");
        } else {
            $delayText = $delayMinutes > 0 ? "⏰ تأخير: {$delayMinutes} دقيقة" : "✅ في الوقت";

            // إذا كانت الصورة متوفرة، أرسلها مع البيانات
            if ($photoBase64 && !empty(trim($photoBase64))) {
                try {
                    $caption = "🔔 <b>تسجيل حضور جديد</b>\n\n";
                    $caption .= "👤 <b>الاسم:</b> {$userName}\n";
                    $caption .= "🏷️ <b>الدور:</b> " . formatRoleName($role) . "\n";
                    $caption .= "📅 <b>التاريخ:</b> " . formatArabicDate($now) . "\n";
                    $caption .= "🕐 <b>الوقت:</b> " . formatArabicTime($now) . "\n";
                    $caption .= "{$delayText}";
                    
                    $photoForTelegram = $savedPhotoAbsolute ?: $photoBase64;
                    $sendAsBase64 = !$savedPhotoAbsolute;

                    error_log("Check-in: Sending photo with data to Telegram for user {$userId}");
                    $telegramResult = sendTelegramPhoto($photoForTelegram, $caption, null, $sendAsBase64);
                    
                    if ($telegramResult) {
                        markAttendanceEventNotificationSent($userId, 'checkin', $today, $recordId);
                        error_log("Attendance check-in sent to Telegram successfully for user {$userId}");
                        if ($savedPhotoAbsolute && file_exists($savedPhotoAbsolute)) {
                            @unlink($savedPhotoAbsolute);
                            $savedPhotoAbsolute = null;
                            $photoDeleted = true;
                        }
                    } else {
                        error_log("Failed to send attendance check-in to Telegram for user {$userId}");
                    }
                } catch (Exception $e) {
                    error_log("Error sending attendance check-in to Telegram: " . $e->getMessage());
                }
            } else {
                // إذا لم تكن هناك صورة، أرسل رسالة نصية فقط (مرة واحدة)
                try {
                    $message = "🔔 <b>تسجيل حضور جديد</b>\n\n";
                    $message .= "👤 <b>الاسم:</b> {$userName}\n";
                    $message .= "🏷️ <b>الدور:</b> " . formatRoleName($role) . "\n";
                    $message .= "📅 <b>التاريخ:</b> " . formatArabicDate($now) . "\n";
                    $message .= "🕐 <b>الوقت:</b> " . formatArabicTime($now) . "\n";
                    $message .= "{$delayText}\n";
                    $message .= "⚠️ <i>لم يتم التقاط صورة</i>";
                    
                    sendTelegramMessage($message);
                    markAttendanceEventNotificationSent($userId, 'checkin', $today, $recordId);
                    error_log("Check-in notification (no photo) sent to Telegram for user {$userId}");
                } catch (Exception $e) {
                    error_log("Error sending check-in notification to Telegram: " . $e->getMessage());
                }
            }
        }
    }
    
    if ($photoDeleted) {
        try {
            $db->execute(
                "UPDATE attendance_records SET photo_path = ? WHERE id = ?",
                ['deleted_after_send', $recordId]
            );
        } catch (Exception $e) {
            error_log("Failed to update deleted photo status for attendance record {$recordId}: " . $e->getMessage());
        }
    }

    try {
        $db->execute(
            "DELETE FROM notifications WHERE user_id = ? AND type = 'attendance_checkin'",
            [$userId]
        );
    } catch (Exception $e) {
        error_log("Failed to clear attendance check-in reminders for user {$userId}: " . $e->getMessage());
    }
    
    return [
        'success' => true,
        'record_id' => $recordId,
        'delay_minutes' => $delayMinutes,
        'message' => $delayMinutes > 0 ? "تم تسجيل الحضور مع تأخير {$delayMinutes} دقيقة" : 'تم تسجيل الحضور في الوقت',
        'photo_path' => $photoDeleted ? 'deleted_after_send' : $savedPhotoRelative
    ];
}

/**
 * تسجيل انصراف
 */
function recordAttendanceCheckOut($userId, $photoBase64 = null) {
    $db = db();
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    
    // الحصول على آخر تسجيل حضور بدون انصراف
    $lastCheckIn = $db->queryOne(
        "SELECT * FROM attendance_records 
         WHERE user_id = ? AND date = ? AND check_out_time IS NULL 
         ORDER BY check_in_time DESC LIMIT 1",
        [$userId, $today]
    );
    
    if (!$lastCheckIn) {
        return ['success' => false, 'message' => 'لا يوجد تسجيل حضور مسبق'];
    }
    
    // حساب ساعات العمل
    $workHours = calculateWorkHours($lastCheckIn['check_in_time'], $now);
    
    // تسجيل القيم المحسوبة للتأكد من صحة الحسابات
    error_log("Checkout calculation: user_id={$userId}, check_in={$lastCheckIn['check_in_time']}, check_out={$now}, work_hours={$workHours}");
    
    // تحديث تسجيل الانصراف
    $checkoutPhotoAbsolute = null;
    $checkoutPhotoRelative = null;

    if ($photoBase64 && !empty(trim($photoBase64))) {
        [$checkoutPhotoAbsolute, $checkoutPhotoRelative] = saveAttendancePhoto($photoBase64, $userId, 'checkout');
    }

    $db->execute(
        "UPDATE attendance_records 
         SET check_out_time = ?, work_hours = ?, checkout_photo_path = ? 
         WHERE id = ?",
        [$now, $workHours, $checkoutPhotoRelative, $lastCheckIn['id']]
    );
    
    // التحقق من أن الساعات تم حفظها بشكل صحيح
    $verifyRecord = $db->queryOne("SELECT work_hours FROM attendance_records WHERE id = ?", [$lastCheckIn['id']]);
    if ($verifyRecord) {
        error_log("Verified saved work_hours: record_id={$lastCheckIn['id']}, saved_work_hours={$verifyRecord['work_hours']}");
    }
    
    // حساب الساعات الحالية اليوم والساعات التراكمية للشهر
    $todayHours = calculateTodayHours($userId, $today);
    $monthHours = calculateMonthHours($userId, date('Y-m'));
    
    // حساب الراتب تلقائياً بعد تسجيل الانصراف
    try {
        $currentMonth = intval(date('m'));
        $currentYear = intval(date('Y'));
        
        // التحقق من وجود سعر ساعة للمستخدم
        $user = $db->queryOne("SELECT hourly_rate, role FROM users WHERE id = ?", [$userId]);
        
        if (!$user) {
            error_log("User not found for salary calculation: user_id={$userId}");
        } else {
            $hourlyRate = floatval($user['hourly_rate'] ?? 0);
            
            if ($hourlyRate > 0) {
                // حساب الراتب تلقائياً للشهر الحالي
                $salaryResult = createOrUpdateSalary($userId, $currentMonth, $currentYear, 0, 0, 'حساب تلقائي بعد تسجيل الانصراف');
                
                if ($salaryResult['success']) {
                    // تم حساب الراتب بنجاح
                    error_log("Salary auto-calculated for user {$userId} after checkout: Month={$currentMonth}/{$currentYear}, Hours={$salaryResult['calculation']['total_hours']}, Total={$salaryResult['calculation']['total_amount']}");
                } else {
                    error_log("Failed to calculate salary for user {$userId} after checkout: {$salaryResult['message']}");
                }
            } else {
                error_log("User {$userId} has no hourly_rate set (value: {$hourlyRate}), skipping salary calculation");
            }
        }
    } catch (Exception $e) {
        // في حالة حدوث خطأ في حساب الراتب، لا نمنع تسجيل الانصراف
        error_log("Error auto-calculating salary after checkout for user {$userId}: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
    }
    
    // الحصول على معلومات المستخدم
    $user = $db->queryOne("SELECT username, full_name, role FROM users WHERE id = ?", [$userId]);
    $userName = $user['full_name'] ?? $user['username'];
    $role = $user['role'] ?? 'unknown';
    
    // إرسال إشعار واحد فقط عبر Telegram (صورة مع جميع البيانات) مع منع التكرار
    $checkoutPhotoDeleted = false;
    $telegramEnabled = isTelegramConfigured();
    $checkoutDate = date('Y-m-d');

    if ($telegramEnabled) {
        $alreadySent = hasAttendanceEventNotificationBeenSent($userId, 'checkout', $checkoutDate);

        if ($alreadySent) {
            error_log("Skipping duplicate attendance check-out notification for user {$userId} on {$checkoutDate}");
        } else {
            // إذا كانت الصورة متوفرة، أرسلها مع البيانات
            if ($photoBase64 && !empty(trim($photoBase64))) {
                try {
                    $caption = "🔔 <b>تسجيل انصراف جديد</b>\n\n";
                    $caption .= "👤 <b>الاسم:</b> {$userName}\n";
                    $caption .= "🏷️ <b>الدور:</b> {$role}\n";
                    $caption .= "📅 <b>التاريخ:</b> {$checkoutDate}\n";
                    $caption .= "🕐 <b>الوقت:</b> " . date('H:i:s') . "\n";
                    $caption .= "⏱️ <b>ساعات هذا التسجيل:</b> {$workHours} ساعة\n";
                    $caption .= "📊 <b>ساعات اليوم:</b> {$todayHours} ساعة\n";
                    $caption .= "📈 <b>ساعات الشهر:</b> {$monthHours} ساعة";
                    
                    $photoForTelegram = $checkoutPhotoAbsolute ?: $photoBase64;
                    $sendAsBase64 = !$checkoutPhotoAbsolute;

                    error_log("Check-out: Sending photo with data to Telegram for user {$userId}");
                    $telegramResult = sendTelegramPhoto($photoForTelegram, $caption, null, $sendAsBase64);
                    
                    if ($telegramResult) {
                        markAttendanceEventNotificationSent($userId, 'checkout', $checkoutDate, $lastCheckIn['id']);
                        error_log("Attendance check-out sent to Telegram successfully for user {$userId}");
                        if ($checkoutPhotoAbsolute && file_exists($checkoutPhotoAbsolute)) {
                            @unlink($checkoutPhotoAbsolute);
                            $checkoutPhotoAbsolute = null;
                            $checkoutPhotoDeleted = true;
                        }
                    } else {
                        error_log("Failed to send attendance check-out to Telegram for user {$userId}");
                    }
                } catch (Exception $e) {
                    error_log("Error sending attendance check-out to Telegram: " . $e->getMessage());
                }
            } else {
                // إذا لم تكن هناك صورة، أرسل رسالة نصية فقط (مرة واحدة)
                try {
                    $message = "🔔 <b>تسجيل انصراف جديد</b>\n\n";
                    $message .= "👤 <b>الاسم:</b> {$userName}\n";
                    $message .= "🏷️ <b>الدور:</b> {$role}\n";
                    $message .= "📅 <b>التاريخ:</b> {$checkoutDate}\n";
                    $message .= "🕐 <b>الوقت:</b> " . date('H:i:s') . "\n";
                    $message .= "⏱️ <b>ساعات هذا التسجيل:</b> {$workHours} ساعة\n";
                    $message .= "📊 <b>ساعات اليوم:</b> {$todayHours} ساعة\n";
                    $message .= "📈 <b>ساعات الشهر:</b> {$monthHours} ساعة\n";
                    $message .= "⚠️ <i>لم يتم التقاط صورة</i>";
                    
                    sendTelegramMessage($message);
                    markAttendanceEventNotificationSent($userId, 'checkout', $checkoutDate, $lastCheckIn['id']);
                    error_log("Check-out notification (no photo) sent to Telegram for user {$userId}");
                } catch (Exception $e) {
                    error_log("Error sending check-out notification to Telegram: " . $e->getMessage());
                }
            }
        }
    }

    if ($checkoutPhotoDeleted) {
        try {
            $db->execute(
                "UPDATE attendance_records SET checkout_photo_path = ? WHERE id = ?",
                ['deleted_after_send', $lastCheckIn['id']]
            );
        } catch (Exception $e) {
            error_log("Failed to update deleted checkout photo status for attendance record {$lastCheckIn['id']}: " . $e->getMessage());
        }
    }
    
    try {
        $db->execute(
            "DELETE FROM notifications WHERE user_id = ? AND type = 'attendance_checkout'",
            [$userId]
        );
    } catch (Exception $e) {
        error_log("Failed to clear attendance checkout reminders for user {$userId}: " . $e->getMessage());
    }
    
    return [
        'success' => true,
        'work_hours' => $workHours,
        'today_hours' => $todayHours,
        'month_hours' => $monthHours,
        'message' => 'تم تسجيل الانصراف بنجاح',
        'checkout_photo_path' => $checkoutPhotoDeleted ? 'deleted_after_send' : $checkoutPhotoRelative
    ];
}

/**
 * حساب ساعات العمل اليوم
 */
function calculateTodayHours($userId, $date) {
    $db = db();
    
    // التحقق من وجود الجدول
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    if (empty($tableCheck)) {
        return 0;
    }
    
    $records = $db->query(
        "SELECT check_in_time, check_out_time, work_hours 
         FROM attendance_records 
         WHERE user_id = ? AND date = ? AND check_out_time IS NOT NULL",
        [$userId, $date]
    );
    
    $totalHours = 0;
    foreach ($records as $record) {
        $totalHours += $record['work_hours'] ?? 0;
    }
    
    return round($totalHours, 2);
}

/**
 * حساب ساعات العمل الشهرية
 */
function calculateMonthHours($userId, $month) {
    $db = db();
    
    // التحقق من وجود الجدول
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    if (empty($tableCheck)) {
        return 0;
    }
    
    $result = $db->queryOne(
        "SELECT COALESCE(SUM(work_hours), 0) as total_hours 
         FROM attendance_records 
         WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? AND check_out_time IS NOT NULL",
        [$userId, $month]
    );
    
    return round($result['total_hours'] ?? 0, 2);
}

/**
 * حساب متوسط التأخير الشهري
 */
function calculateAverageDelay($userId, $month) {
    $db = db();
    
    // التحقق من وجود الجدول
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    if (empty($tableCheck)) {
        return ['average' => 0, 'count' => 0];
    }
    
    $result = $db->queryOne(
        "SELECT COALESCE(AVG(delay_minutes), 0) as avg_delay, COUNT(*) as count 
         FROM attendance_records 
         WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? AND delay_minutes > 0",
        [$userId, $month]
    );
    
    return [
        'average' => round($result['avg_delay'] ?? 0, 2),
        'count' => $result['count'] ?? 0
    ];
}

/**
 * الحصول على سجلات الحضور اليوم
 */
function getTodayAttendanceRecords($userId, $date = null) {
    $db = db();
    $date = $date ?? date('Y-m-d');
    
    // التحقق من وجود الجدول
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    if (empty($tableCheck)) {
        return [];
    }
    
    return $db->query(
        "SELECT * FROM attendance_records 
         WHERE user_id = ? AND date = ? 
         ORDER BY check_in_time ASC",
        [$userId, $date]
    );
}

/**
 * الحصول على إحصائيات الحضور
 */
function getAttendanceStatistics($userId, $month = null) {
    $db = db();
    $month = $month ?? date('Y-m');
    
    $stats = [
        'total_days' => 0,
        'present_days' => 0,
        'absent_days' => 0,
        'total_hours' => 0,
        'average_delay' => 0,
        'delay_count' => 0,
        'today_hours' => 0,
        'today_records' => []
    ];
    
    // التحقق من وجود الجدول
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
    if (empty($tableCheck)) {
        return $stats;
    }
    
    // إحصائيات الشهر
    // يجب حساب الساعات فقط من السجلات المكتملة (check_out_time IS NOT NULL)
    $monthStats = $db->queryOne(
        "SELECT 
            COUNT(DISTINCT date) as present_days,
            COALESCE(SUM(work_hours), 0) as total_hours,
            COALESCE(AVG(delay_minutes), 0) as avg_delay,
            COUNT(CASE WHEN delay_minutes > 0 THEN 1 END) as delay_count
         FROM attendance_records 
         WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
         AND check_out_time IS NOT NULL
         AND work_hours IS NOT NULL
         AND work_hours > 0",
        [$userId, $month]
    );
    
    $stats['present_days'] = $monthStats['present_days'] ?? 0;
    $stats['total_hours'] = round($monthStats['total_hours'] ?? 0, 2);
    $stats['average_delay'] = round($monthStats['avg_delay'] ?? 0, 2);
    $stats['delay_count'] = $monthStats['delay_count'] ?? 0;
    
    // ساعات اليوم
    $today = date('Y-m-d');
    $stats['today_hours'] = calculateTodayHours($userId, $today);
    $stats['today_records'] = getTodayAttendanceRecords($userId, $today);
    
    return $stats;
}

