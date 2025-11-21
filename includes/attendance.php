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

// تحميل notifications.php إذا لم يكن محملاً بالفعل
if (!function_exists('createNotification')) {
    require_once __DIR__ . '/notifications.php';
}

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
    } elseif ($role === 'sales') {
        // المندوبين
        return ['start' => '10:00:00', 'end' => '19:00:00'];
    } else {
        // عمال الإنتاج
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
 * استخراج قيم الشهر/السنة بالشكل القياسي YYYY-MM
 *
 * @return array{month_key:string, month:int, year:int}
 */
function resolveAttendanceMonthParts($month, ?int $year = null): array
{
    if (is_string($month) && preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
        $resolvedYear  = (int) $matches[1];
        $resolvedMonth = (int) $matches[2];
        return [
            'month_key' => sprintf('%04d-%02d', $resolvedYear, $resolvedMonth),
            'month'     => $resolvedMonth,
            'year'      => $resolvedYear,
        ];
    }

    $resolvedMonth = (int) $month;
    $resolvedYear  = $year !== null ? (int) $year : (int) date('Y');

    if ($resolvedMonth < 1) {
        $resolvedMonth = 1;
    } elseif ($resolvedMonth > 12) {
        $resolvedMonth = 12;
    }

    return [
        'month_key' => sprintf('%04d-%02d', $resolvedYear, $resolvedMonth),
        'month'     => $resolvedMonth,
        'year'      => $resolvedYear,
    ];
}

/**
 * حساب ملخص التأخير الشهري للمستخدم اعتماداً على أول تسجيل حضور يومي.
 *
 * @return array{
 *     total_minutes: float,
 *     average_minutes: float,
 *     delay_days: int,
 *     attendance_days: int,
 *     details: array<string, array{delay: float, first_check_in: string|null}>
 * }
 */
function calculateMonthlyDelaySummary(int $userId, $month, ?int $year = null): array
{
    $db = db();
    $parts = resolveAttendanceMonthParts($month, $year);
    $monthKey = $parts['month_key'];

    $summary = [
        'total_minutes'    => 0.0,
        'average_minutes'  => 0.0,
        'delay_days'       => 0,
        'attendance_days'  => 0,
        'details'          => [],
    ];

    // المديرون لا يمتلكون أوقات حضور رسمية
    $workTime = getOfficialWorkTime($userId);
    if ($workTime === null) {
        return $summary;
    }

    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");

    if (!empty($tableCheck)) {
        $records = $db->query(
            "SELECT date, check_in_time, delay_minutes
             FROM attendance_records
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             ORDER BY date ASC, check_in_time ASC",
            [$userId, $monthKey]
        );

        foreach ($records as $record) {
            $attendanceDate = $record['date'];
            if (!isset($summary['details'][$attendanceDate])) {
                $delayValue = 0.0;
                $firstCheckInRaw = $record['check_in_time'] ?? null;
                $firstCheckInCombined = null;

                if ($firstCheckInRaw) {
                    $firstCheckInCombined = $firstCheckInRaw;

                    // في بعض قواعد البيانات قد يتم تخزين الوقت فقط بدون التاريخ
                    if (strpos($firstCheckInRaw, '-') === false && strpos($firstCheckInRaw, 'T') === false && strlen($firstCheckInRaw) <= 8) {
                        $firstCheckInCombined = $attendanceDate . ' ' . $firstCheckInRaw;
                    }

                    $checkInTs = strtotime($firstCheckInCombined);
                    $officialTs = strtotime($attendanceDate . ' ' . ($workTime['start'] ?? '00:00:00'));

                    if ($checkInTs !== false && $officialTs !== false && $checkInTs > $officialTs) {
                        $delayValue = round(($checkInTs - $officialTs) / 60, 2);
                    }
                }

                if ($delayValue <= 0 && isset($record['delay_minutes'])) {
                    $fallbackDelay = (float) $record['delay_minutes'];
                    if ($fallbackDelay > 0) {
                        $delayValue = $fallbackDelay;
                    }
                }

                if ($delayValue < 0) {
                    $delayValue = 0.0;
                }

                $summary['details'][$attendanceDate] = [
                    'delay'           => $delayValue,
                    'first_check_in'  => $firstCheckInCombined,
                ];
            }
        }
    } else {
        // fallback للجدول القديم attendance
        $legacyRecords = $db->query(
            "SELECT date, check_in
             FROM attendance
             WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ?
             ORDER BY date ASC, check_in ASC",
            [$userId, $monthKey]
        );

        foreach ($legacyRecords as $record) {
            $attendanceDate = $record['date'];
            if (!isset($summary['details'][$attendanceDate])) {
                $checkInTime = $record['check_in'] ?? null;
                $combinedCheckIn = $checkInTime ? ($attendanceDate . ' ' . $checkInTime) : null;
                $officialDateTime = $attendanceDate . ' ' . ($workTime['start'] ?? '00:00:00');
                $delayValue = 0.0;

                if ($combinedCheckIn) {
                    $checkInTs = strtotime($combinedCheckIn);
                    $officialTs = strtotime($officialDateTime);
                    if ($checkInTs !== false && $officialTs !== false && $checkInTs > $officialTs) {
                        $delayValue = round(($checkInTs - $officialTs) / 60, 2);
                    }
                }

                $summary['details'][$attendanceDate] = [
                    'delay'           => $delayValue,
                    'first_check_in'  => $combinedCheckIn,
                ];
            }
        }
    }

    if (empty($summary['details'])) {
        return $summary;
    }

    $totalDelay = 0.0;
    $delayDays = 0;

    foreach ($summary['details'] as $detail) {
        $delay = (float) ($detail['delay'] ?? 0.0);
        if ($delay > 0) {
            $totalDelay += $delay;
            $delayDays++;
        }
    }

    $attendanceDays = count($summary['details']);
    $summary['attendance_days'] = $attendanceDays;
    $summary['delay_days'] = $delayDays;
    $summary['total_minutes'] = round($totalDelay, 2);
    $summary['average_minutes'] = $attendanceDays > 0 ? round($totalDelay / $attendanceDays, 2) : 0.0;

    return $summary;
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
 * تنظيف صور الحضور والانصراف القديمة
 * يحذف الصور التي مر عليها أكثر من عدد الأيام المحدد
 * @param int $daysOld عدد الأيام القديمة (افتراضي 30 يوم)
 * @return array إحصائيات عملية الحذف
 */
function cleanupOldAttendancePhotos($daysOld = 30) {
    $stats = [
        'deleted_files' => 0,
        'deleted_folders' => 0,
        'errors' => 0,
        'total_size_freed' => 0,
        'processed_files' => 0
    ];

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if ($uploadsRoot === false) {
        $uploadsRoot = __DIR__ . '/../uploads';
        if (!is_dir($uploadsRoot)) {
            return $stats;
        }
    }

    $attendanceDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'attendance';
    if (!is_dir($attendanceDir)) {
        return $stats;
    }

    $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
    $cutoffDate = date('Y-m-d', $cutoffTime);

    try {
        // قراءة جميع المجلدات في مجلد attendance
        $monthFolders = glob($attendanceDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        
        foreach ($monthFolders as $monthFolder) {
            $folderName = basename($monthFolder);
            
            // التحقق من أن اسم المجلد بصيغة YYYY-MM
            if (!preg_match('/^\d{4}-\d{2}$/', $folderName)) {
                continue;
            }

            // تحديد آخر يوم في الشهر
            $lastDayOfMonth = date('Y-m-t', strtotime($folderName . '-01'));
            
            // إذا كان آخر يوم في الشهر أقدم من تاريخ القطع، احذف المجلد بالكامل
            if ($lastDayOfMonth < $cutoffDate) {
                $files = glob($monthFolder . DIRECTORY_SEPARATOR . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $fileSize = filesize($file);
                        if (@unlink($file)) {
                            $stats['deleted_files']++;
                            $stats['total_size_freed'] += $fileSize;
                            $stats['processed_files']++;
                        } else {
                            $stats['errors']++;
                        }
                    }
                }
                
                // محاولة حذف المجلد إذا كان فارغاً
                if (@rmdir($monthFolder)) {
                    $stats['deleted_folders']++;
                }
            } else {
                // المجلد يحتوي على ملفات من الشهر الحالي أو حديثة
                // نحتاج إلى فحص الملفات الفردية
                $files = glob($monthFolder . DIRECTORY_SEPARATOR . '*.jpg');
                foreach ($files as $file) {
                    $stats['processed_files']++;
                    $fileTime = filemtime($file);
                    
                    if ($fileTime !== false && $fileTime < $cutoffTime) {
                        $fileSize = filesize($file);
                        if (@unlink($file)) {
                            $stats['deleted_files']++;
                            $stats['total_size_freed'] += $fileSize;
                        } else {
                            $stats['errors']++;
                        }
                    }
                }
                
                // محاولة حذف المجلد إذا أصبح فارغاً
                $remainingFiles = glob($monthFolder . DIRECTORY_SEPARATOR . '*');
                if (empty($remainingFiles)) {
                    if (@rmdir($monthFolder)) {
                        $stats['deleted_folders']++;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('cleanupOldAttendancePhotos: Error: ' . $e->getMessage());
        $stats['errors']++;
    }

    // تحديث قاعدة البيانات لتعيين photo_path و checkout_photo_path إلى NULL للسجلات المحذوفة
    $db = db();
    if ($db) {
        try {
            // البحث عن السجلات التي لها مسارات صور تم حذفها
            $records = $db->query(
                "SELECT id, photo_path, checkout_photo_path 
                 FROM attendance_records 
                 WHERE (photo_path IS NOT NULL OR checkout_photo_path IS NOT NULL)
                 AND DATE(created_at) < ?",
                [$cutoffDate]
            );

            foreach ($records as $record) {
                $updateFields = [];

                // التحقق من photo_path
                if (!empty($record['photo_path'])) {
                    $photoPath = getAttendancePhotoAbsolutePath($record['photo_path']);
                    if ($photoPath === null || !file_exists($photoPath)) {
                        $updateFields[] = 'photo_path = NULL';
                    }
                }

                // التحقق من checkout_photo_path
                if (!empty($record['checkout_photo_path'])) {
                    $checkoutPhotoPath = getAttendancePhotoAbsolutePath($record['checkout_photo_path']);
                    if ($checkoutPhotoPath === null || !file_exists($checkoutPhotoPath)) {
                        $updateFields[] = 'checkout_photo_path = NULL';
                    }
                }

                if (!empty($updateFields)) {
                    $updateSql = "UPDATE attendance_records SET " . implode(', ', $updateFields) . " WHERE id = ?";
                    $db->execute($updateSql, [$record['id']]);
                }
            }
        } catch (Exception $e) {
            error_log('cleanupOldAttendancePhotos: Database update error: ' . $e->getMessage());
            $stats['errors']++;
        }
    }

    return $stats;
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
    
    // حفظ نسخة من الصورة الأصلية قبل أي معالجة (لإرسالها إلى تليجرام)
    $originalPhotoBase64 = $photoBase64 ? (string)$photoBase64 : null;
    
    error_log("Check-in: Original photo received - length: " . ($originalPhotoBase64 ? strlen($originalPhotoBase64) : 0));

    if ($photoBase64 && !empty(trim($photoBase64))) {
        [$savedPhotoAbsolute, $savedPhotoRelative] = saveAttendancePhoto($photoBase64, $userId, 'checkin');
        error_log("Check-in: Photo saved - absolute: " . ($savedPhotoAbsolute ?: 'null') . ", relative: " . ($savedPhotoRelative ?: 'null'));
    } else {
        error_log("Check-in: No photo to save - photoBase64 is empty or null");
    }

    $storedPhotoValue = $savedPhotoRelative ?? ($photoBase64 ? 'captured' : null);

    $result = $db->execute(
        "INSERT INTO attendance_records (user_id, date, check_in_time, delay_minutes, photo_path, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())",
        [$userId, $today, $now, $delayMinutes, $storedPhotoValue]
    );
    
    $recordId = $result['insert_id'];
    
    // التأكد من وجود عمود delay_count
    ensureDelayCountColumn();
    
    // معالجة منطق التأخير إذا كان هناك تأخير
    if ($delayMinutes > 0) {
        // الحصول على عداد التأخيرات الحالي
        $currentDelayCount = (int)$db->queryOne(
            "SELECT delay_count FROM users WHERE id = ?",
            [$userId]
        )['delay_count'] ?? 0;
        
        // زيادة عداد التأخيرات بمقدار 1
        $newDelayCount = $currentDelayCount + 1;
        $db->execute(
            "UPDATE users SET delay_count = ? WHERE id = ?",
            [$newDelayCount, $userId]
        );
        
        // الحصول على معلومات المستخدم
        $user = $db->queryOne("SELECT username, full_name, role FROM users WHERE id = ?", [$userId]);
        $userName = $user['full_name'] ?? $user['username'];
        $role = $user['role'] ?? 'unknown';
        
        // إرسال إشعار للموظف بعدد التأخيرات الحالية
        $delayMessage = "تم تسجيل حضورك مع تأخير {$delayMinutes} دقيقة. عدد حالات التأخير الحالية لهذا الشهر: {$newDelayCount}";
        
        createNotification(
            $userId,
            'تنبيه: تأخير في الحضور',
            $delayMessage,
            'warning',
            getAttendanceReminderLink($role),
            false // لا نرسل عبر Telegram هنا لأن هناك إشعار Telegram منفصل للحضور
        );
        
        // إذا وصل عداد التأخيرات إلى 3 أو أكثر، إرسال إشعار للمدير
        if ($newDelayCount >= 3) {
            // الحصول على جميع المديرين
            $managers = $db->query(
                "SELECT id FROM users WHERE role = 'manager' AND status = 'active'"
            );
            
            foreach ($managers as $manager) {
                $managerId = (int)$manager['id'];
                $managerMessage = "تنبيه: الموظف {$userName} ({$role}) قد تجاوز 3 حالات حضور متأخر خلال الشهر الحالي. إجمالي حالات التأخير: {$newDelayCount}";
                
                createNotification(
                    $managerId,
                    'تنبيه: موظف تجاوز حد التأخير',
                    $managerMessage,
                    'error',
                    null,
                    true // إرسال عبر Telegram
                );
            }
            
            error_log("Delay count alert sent to managers for user {$userId} with {$newDelayCount} delays");
        }
        
        error_log("User {$userId} check-in delay: {$delayMinutes} minutes, total delay count: {$newDelayCount}");
    } else {
        // الحصول على معلومات المستخدم (في حالة عدم وجود تأخير)
        $user = $db->queryOne("SELECT username, full_name, role FROM users WHERE id = ?", [$userId]);
        $userName = $user['full_name'] ?? $user['username'];
        $role = $user['role'] ?? 'unknown';
    }
    
    // إرسال إشعار واحد فقط عبر Telegram (صورة مع جميع البيانات) مع منع التكرار
    $photoDeleted = false;
    $telegramEnabled = isTelegramConfigured();
    
    error_log("Check-in: Telegram notification check - enabled: " . ($telegramEnabled ? 'yes' : 'no'));

    if ($telegramEnabled) {
        // السماح بإرسال عدة صور لنفس المستخدم في نفس اليوم
        $delayText = $delayMinutes > 0 ? "⏰ تأخير: {$delayMinutes} دقيقة" : "✅ في الوقت";

        // إذا كانت الصورة متوفرة، أرسلها مع البيانات
        // استخدام الصورة الأصلية المحفوظة قبل الحفظ
        $photoToSend = $originalPhotoBase64 ?? $photoBase64;
        
        error_log("Check-in: Checking photo availability:");
        error_log("   - originalPhotoBase64: " . ($originalPhotoBase64 ? 'exists (length: ' . strlen($originalPhotoBase64) . ')' : 'null'));
        error_log("   - photoBase64: " . ($photoBase64 ? 'exists (length: ' . strlen($photoBase64) . ')' : 'null'));
        error_log("   - savedPhotoAbsolute: " . ($savedPhotoAbsolute ? $savedPhotoAbsolute : 'null'));
        error_log("   - photoToSend: " . ($photoToSend ? 'exists (length: ' . strlen($photoToSend) . ')' : 'null'));
        
        if ($photoToSend && !empty(trim($photoToSend))) {
            try {
                $caption = "🔔 <b>تسجيل حضور جديد</b>\n\n";
                $caption .= "👤 <b>الاسم:</b> {$userName}\n";
                $caption .= "🏷️ <b>الدور:</b> " . formatRoleName($role) . "\n";
                $caption .= "📅 <b>التاريخ:</b> " . formatArabicDate($now) . "\n";
                $caption .= "🕐 <b>الوقت:</b> " . formatArabicTime($now) . "\n";
                $caption .= "{$delayText}";
                
                // استخدام الصورة المحفوظة إذا كانت موجودة، وإلا استخدم base64
                if ($savedPhotoAbsolute && file_exists($savedPhotoAbsolute)) {
                    $photoForTelegram = $savedPhotoAbsolute;
                    $sendAsBase64 = false;
                    error_log("Check-in: Using saved photo file: {$savedPhotoAbsolute}");
                } else {
                    $photoForTelegram = $photoToSend;
                    $sendAsBase64 = true;
                    error_log("Check-in: Using base64 photo, length: " . strlen($photoToSend));
                }

                error_log("Check-in: Sending photo with data to Telegram for user {$userId}, sendAsBase64: " . ($sendAsBase64 ? 'yes' : 'no'));
                error_log("Check-in: Photo data preview: " . substr($photoForTelegram, 0, 100) . '...');
                
                $telegramResult = sendTelegramPhoto($photoForTelegram, $caption, null, $sendAsBase64);
                
                if ($telegramResult) {
                    // تسجيل الإرسال (للمراجعة فقط، بدون منع التكرار)
                    markAttendanceEventNotificationSent($userId, 'checkin', $today, $recordId);
                    error_log("✅ Attendance check-in sent to Telegram successfully for user {$userId}");
                    if ($savedPhotoAbsolute && file_exists($savedPhotoAbsolute)) {
                        @unlink($savedPhotoAbsolute);
                        $savedPhotoAbsolute = null;
                        $photoDeleted = true;
                    }
                } else {
                    error_log("❌ Failed to send attendance check-in to Telegram for user {$userId}");
                    error_log("   - Check error_log for more details");
                }
            } catch (Exception $e) {
                error_log("Error sending attendance check-in to Telegram: " . $e->getMessage());
            }
        } else {
            error_log("Check-in: No photo to send - photoToSend is empty or null");
            // إذا لم تكن هناك صورة، أرسل رسالة نصية فقط
            try {
                $message = "🔔 <b>تسجيل حضور جديد</b>\n\n";
                $message .= "👤 <b>الاسم:</b> {$userName}\n";
                $message .= "🏷️ <b>الدور:</b> " . formatRoleName($role) . "\n";
                $message .= "📅 <b>التاريخ:</b> " . formatArabicDate($now) . "\n";
                $message .= "🕐 <b>الوقت:</b> " . formatArabicTime($now) . "\n";
                $message .= "{$delayText}\n";
                $message .= "⚠️ <i>لم يتم التقاط صورة</i>";
                
                sendTelegramMessage($message);
                // تسجيل الإرسال (للمراجعة فقط، بدون منع التكرار)
                markAttendanceEventNotificationSent($userId, 'checkin', $today, $recordId);
                error_log("Check-in notification (no photo) sent to Telegram for user {$userId}");
            } catch (Exception $e) {
                error_log("Error sending check-in notification to Telegram: " . $e->getMessage());
            }
        }
    } else {
        error_log("Check-in: Telegram is not enabled - skipping notification");
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
    error_log("=== recordAttendanceCheckOut START - user_id: {$userId}, photoBase64: " . ($photoBase64 ? 'exists (length: ' . strlen($photoBase64) . ')' : 'null') . " ===");
    
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
    
    // تحديد تاريخ الحضور المستخدم في سجلات الرواتب (يعتمد على يوم الحضور الفعلي)
    $attendanceDate = $lastCheckIn['date'] ?? $today;
    try {
        $attendanceDateTime = new DateTime($attendanceDate);
    } catch (Exception $e) {
        $attendanceDateTime = new DateTime($today);
    }
    $attendanceMonthNumber = (int)$attendanceDateTime->format('n');
    $attendanceYearNumber  = (int)$attendanceDateTime->format('Y');
    $attendanceMonthKey    = $attendanceDateTime->format('Y-m');

    // حساب ساعات العمل
    $workHours = calculateWorkHours($lastCheckIn['check_in_time'], $now);
    
    // تسجيل القيم المحسوبة للتأكد من صحة الحسابات
    error_log("Checkout calculation: user_id={$userId}, check_in={$lastCheckIn['check_in_time']}, check_out={$now}, work_hours={$workHours}");
    
    // تحديث تسجيل الانصراف
    $checkoutPhotoAbsolute = null;
    $checkoutPhotoRelative = null;
    
    // حفظ نسخة من الصورة الأصلية قبل أي معالجة (لإرسالها إلى تليجرام)
    $originalCheckoutPhotoBase64 = $photoBase64 ? (string)$photoBase64 : null;
    
    error_log("Check-out: Original photo received - length: " . ($originalCheckoutPhotoBase64 ? strlen($originalCheckoutPhotoBase64) : 0));

    if ($photoBase64 && !empty(trim($photoBase64))) {
        [$checkoutPhotoAbsolute, $checkoutPhotoRelative] = saveAttendancePhoto($photoBase64, $userId, 'checkout');
        error_log("Check-out: Photo saved - absolute: " . ($checkoutPhotoAbsolute ?: 'null') . ", relative: " . ($checkoutPhotoRelative ?: 'null'));
    } else {
        error_log("Check-out: No photo to save - photoBase64 is empty or null");
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
    
    // حساب الساعات الحالية لليوم والساعات التراكمية للشهر بناءً على يوم الحضور الفعلي
    $todayHours = calculateTodayHours($userId, $attendanceDateTime->format('Y-m-d'));
    $monthHours = calculateMonthHours($userId, $attendanceMonthKey);
    
    // حساب الراتب تلقائياً بعد تسجيل الانصراف
    try {
        // التحقق من وجود سعر ساعة للمستخدم
        $user = $db->queryOne("SELECT hourly_rate, role FROM users WHERE id = ?", [$userId]);
        
        if (!$user) {
            error_log("User not found for salary calculation: user_id={$userId}");
        } else {
            $hourlyRate = floatval($user['hourly_rate'] ?? 0);
            
            if ($hourlyRate > 0) {
                // حساب الراتب تلقائياً للشهر الحالي
                $salaryResult = createOrUpdateSalary(
                    $userId,
                    $attendanceMonthNumber,
                    $attendanceYearNumber,
                    0,
                    0,
                    'حساب تلقائي بعد تسجيل الانصراف'
                );
                
                if ($salaryResult['success']) {
                    // تم حساب الراتب بنجاح
                    error_log(
                        "Salary auto-calculated for user {$userId} after checkout: Month={$attendanceMonthNumber}/{$attendanceYearNumber}, Hours={$salaryResult['calculation']['total_hours']}, Total={$salaryResult['calculation']['total_amount']}"
                    );
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
    
    error_log("Check-out: Telegram notification check - enabled: " . ($telegramEnabled ? 'yes' : 'no'));

    if ($telegramEnabled) {
        // السماح بإرسال عدة صور لنفس المستخدم في نفس اليوم
        // إذا كانت الصورة متوفرة، أرسلها مع البيانات
        // استخدام الصورة الأصلية المحفوظة قبل الحفظ
        $photoToSend = $originalCheckoutPhotoBase64 ?? $photoBase64;
        
        error_log("Check-out: Checking photo availability:");
        error_log("   - originalCheckoutPhotoBase64: " . ($originalCheckoutPhotoBase64 ? 'exists (length: ' . strlen($originalCheckoutPhotoBase64) . ')' : 'null'));
        error_log("   - photoBase64: " . ($photoBase64 ? 'exists (length: ' . strlen($photoBase64) . ')' : 'null'));
        error_log("   - checkoutPhotoAbsolute: " . ($checkoutPhotoAbsolute ? $checkoutPhotoAbsolute : 'null'));
        error_log("   - photoToSend: " . ($photoToSend ? 'exists (length: ' . strlen($photoToSend) . ')' : 'null'));
        
        if ($photoToSend && !empty(trim($photoToSend))) {
            try {
                $caption = "🔔 <b>تسجيل انصراف جديد</b>\n\n";
                $caption .= "👤 <b>الاسم:</b> {$userName}\n";
                $caption .= "🏷️ <b>الدور:</b> {$role}\n";
                $caption .= "📅 <b>التاريخ:</b> {$checkoutDate}\n";
                $caption .= "🕐 <b>الوقت:</b> " . date('H:i:s') . "\n";
                $caption .= "⏱️ <b>ساعات هذا التسجيل:</b> {$workHours} ساعة\n";
                $caption .= "📊 <b>ساعات اليوم:</b> {$todayHours} ساعة\n";
                $caption .= "📈 <b>ساعات الشهر:</b> {$monthHours} ساعة";
                
                // استخدام الصورة المحفوظة إذا كانت موجودة، وإلا استخدم base64
                if ($checkoutPhotoAbsolute && file_exists($checkoutPhotoAbsolute)) {
                    $photoForTelegram = $checkoutPhotoAbsolute;
                    $sendAsBase64 = false;
                    error_log("Check-out: Using saved photo file: {$checkoutPhotoAbsolute}");
                } else {
                    $photoForTelegram = $photoToSend;
                    $sendAsBase64 = true;
                    error_log("Check-out: Using base64 photo, length: " . strlen($photoToSend));
                }

                error_log("Check-out: Sending photo with data to Telegram for user {$userId}, sendAsBase64: " . ($sendAsBase64 ? 'yes' : 'no'));
                error_log("Check-out: Photo data preview: " . substr($photoForTelegram, 0, 100) . '...');
                
                $telegramResult = sendTelegramPhoto($photoForTelegram, $caption, null, $sendAsBase64);
                
                if ($telegramResult) {
                    // تسجيل الإرسال (للمراجعة فقط، بدون منع التكرار)
                    markAttendanceEventNotificationSent($userId, 'checkout', $checkoutDate, $lastCheckIn['id']);
                    error_log("✅ Attendance check-out sent to Telegram successfully for user {$userId}");
                    if ($checkoutPhotoAbsolute && file_exists($checkoutPhotoAbsolute)) {
                        @unlink($checkoutPhotoAbsolute);
                        $checkoutPhotoAbsolute = null;
                        $checkoutPhotoDeleted = true;
                    }
                } else {
                    error_log("❌ Failed to send attendance check-out to Telegram for user {$userId}");
                    error_log("   - Check error_log for more details");
                }
            } catch (Exception $e) {
                error_log("Error sending attendance check-out to Telegram: " . $e->getMessage());
            }
        } else {
            error_log("Check-out: No photo to send - photoToSend is empty or null");
            // إذا لم تكن هناك صورة، أرسل رسالة نصية فقط
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
                // تسجيل الإرسال (للمراجعة فقط، بدون منع التكرار)
                markAttendanceEventNotificationSent($userId, 'checkout', $checkoutDate, $lastCheckIn['id']);
                error_log("Check-out notification (no photo) sent to Telegram for user {$userId}");
            } catch (Exception $e) {
                error_log("Error sending check-out notification to Telegram: " . $e->getMessage());
            }
        }
    } else {
        error_log("Check-out: Telegram is not enabled - skipping notification");
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

    // في حال كان اليوم هو آخر يوم في الشهر، يتم إرسال تقرير التأخيرات الشهري تلقائياً (مرة واحدة فقط)
    if (date('Y-m-d') === date('Y-m-t')) {
        try {
            maybeSendMonthlyAttendanceTelegramReport((int) date('n'), (int) date('Y'));
        } catch (Throwable $reportException) {
            error_log('Automatic monthly attendance report dispatch failed: ' . $reportException->getMessage());
        }
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
    $summary = calculateMonthlyDelaySummary($userId, $month);

    return [
        'average' => $summary['average_minutes'],
        'count'   => $summary['delay_days'],
        'total'   => $summary['total_minutes'],
        'days'    => $summary['attendance_days'],
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
        'total_delay_minutes' => 0,
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
    
    $delaySummary = calculateMonthlyDelaySummary($userId, $month);
    $stats['average_delay'] = $delaySummary['average_minutes'];
    $stats['delay_count'] = $delaySummary['delay_days'];
    $stats['total_delay_minutes'] = $delaySummary['total_minutes'];
    
    // ساعات اليوم
    $today = date('Y-m-d');
    $stats['today_hours'] = calculateTodayHours($userId, $today);
    $stats['today_records'] = getTodayAttendanceRecords($userId, $today);
    
    return $stats;
}

/**
 * التأكد من وجود جدول سجلات تقارير التأخير الشهرية
 */
function ensureAttendanceMonthlyReportLogTable(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    try {
        $db = db();
        $db->execute("
            CREATE TABLE IF NOT EXISTS `attendance_monthly_report_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `month_key` char(7) NOT NULL COMMENT 'YYYY-MM',
              `month_number` tinyint(2) NOT NULL,
              `year_number` smallint(4) NOT NULL,
              `sent_via` varchar(32) NOT NULL COMMENT 'telegram_auto, telegram_manual, manual_export, ...',
              `triggered_by` int(11) DEFAULT NULL COMMENT 'المستخدم الذي أنشأ التقرير يدوياً (إن وجد)',
              `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `report_snapshot` longtext DEFAULT NULL COMMENT 'نسخة JSON من التقرير',
              PRIMARY KEY (`id`),
              KEY `month_key_idx` (`month_key`),
              KEY `sent_via_idx` (`sent_via`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ensured = true;
    } catch (Exception $e) {
        error_log('ensureAttendanceMonthlyReportLogTable error: ' . $e->getMessage());
    }
}

/**
 * التحقق مما إذا تم إرسال تقرير التأخيرات الشهري عبر قناة معينة
 */
function hasAttendanceMonthlyReportBeenSent(string $monthKey, string $via = 'telegram_auto'): bool
{
    ensureAttendanceMonthlyReportLogTable();

    try {
        $db = db();
        $row = $db->queryOne(
            "SELECT id FROM attendance_monthly_report_logs WHERE month_key = ? AND sent_via = ? LIMIT 1",
            [$monthKey, $via]
        );
        return !empty($row);
    } catch (Exception $e) {
        error_log('hasAttendanceMonthlyReportBeenSent error: ' . $e->getMessage());
        return false;
    }
}

/**
 * تسجيل إرسال تقرير التأخير الشهري
 *
 * @param array<string,mixed>|null $snapshot
 */
function markAttendanceMonthlyReportSent(
    string $monthKey,
    string $via,
    ?array $snapshot = null,
    ?int $triggeredBy = null
): void {
    ensureAttendanceMonthlyReportLogTable();

    $parts = resolveAttendanceMonthParts($monthKey);

    try {
        $db = db();
        $db->execute(
            "INSERT INTO attendance_monthly_report_logs (month_key, month_number, year_number, sent_via, triggered_by, report_snapshot, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $parts['month_key'],
                $parts['month'],
                $parts['year'],
                $via,
                $triggeredBy,
                $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    } catch (Exception $e) {
        error_log('markAttendanceMonthlyReportSent error: ' . $e->getMessage());
    }
}

/**
 * إنشاء تقرير حضور وتأخيرات شهري لجميع الموظفين
 *
 * @return array{
 *   month:int,
 *   year:int,
 *   month_key:string,
 *   generated_at:string,
 *   total_employees:int,
 *   total_hours:float,
 *   total_delay_minutes:float,
 *   average_delay_minutes:float,
 *   total_salary_amount:float,
 *   employees: array<int, array<string,mixed>>
 * }
 */
function getMonthlyAttendanceDelayReport(int $month, int $year): array
{
    $db = db();
    $parts = resolveAttendanceMonthParts($month, $year);

    $report = [
        'month'                 => $parts['month'],
        'year'                  => $parts['year'],
        'month_key'             => $parts['month_key'],
        'generated_at'          => date('Y-m-d H:i:s'),
        'total_employees'       => 0,
        'total_hours'           => 0.0,
        'total_delay_minutes'   => 0.0,
        'average_delay_minutes' => 0.0,
        'total_salary_amount'   => 0.0,
        'employees'             => [],
    ];

    $users = $db->query(
        "SELECT id, username, full_name, role, hourly_rate
         FROM users
         WHERE status = 'active'
         AND role != 'manager'
         ORDER BY full_name ASC"
    );

    foreach ($users as $user) {
        $userId = (int) $user['id'];
        $delaySummary = calculateMonthlyDelaySummary($userId, $parts['month'], $parts['year']);

        // ترك فقط المستخدمين الذين لديهم سجلات حضور في هذا الشهر
        if ($delaySummary['attendance_days'] === 0 && $delaySummary['total_minutes'] <= 0) {
            continue;
        }

        $monthHours = calculateMonthHours($userId, $parts['month_key']);

        $salarySummary = getSalarySummary($userId, $parts['month'], $parts['year']);
        $salaryAmount = 0.0;
        $salaryStatus = 'غير محسوب';

        if (!empty($salarySummary['exists']) && !empty($salarySummary['salary'])) {
            $salaryAmount = (float) ($salarySummary['salary']['total_amount'] ?? 0);
            $salaryStatus = $salarySummary['salary']['status'] ?? 'غير محدد';
        } elseif (!empty($salarySummary['calculation']) && !empty($salarySummary['calculation']['success'])) {
            $salaryAmount = (float) ($salarySummary['calculation']['total_amount'] ?? 0);
            $salaryStatus = 'محسوب (غير محفوظ)';
        }

        $employeeName = $user['full_name'] ?? $user['username'] ?? ('موظف #' . $userId);

        $report['employees'][] = [
            'user_id'               => $userId,
            'name'                  => $employeeName,
            'role'                  => $user['role'],
            'hourly_rate'           => (float) ($user['hourly_rate'] ?? 0),
            'attendance_days'       => $delaySummary['attendance_days'],
            'delay_days'            => $delaySummary['delay_days'],
            'total_delay_minutes'   => $delaySummary['total_minutes'],
            'average_delay_minutes' => $delaySummary['average_minutes'],
            'total_hours'           => $monthHours,
            'salary_amount'         => $salaryAmount,
            'salary_status'         => $salaryStatus,
        ];

        $report['total_hours'] += $monthHours;
        $report['total_delay_minutes'] += $delaySummary['total_minutes'];
        $report['total_salary_amount'] += $salaryAmount;
    }

    $report['total_employees'] = count($report['employees']);
    $report['average_delay_minutes'] = $report['total_employees'] > 0
        ? round($report['total_delay_minutes'] / $report['total_employees'], 2)
        : 0.0;

    return $report;
}

/**
 * إنشاء ملف CSV مؤقت لتقرير الحضور الشهري
 */
function buildMonthlyAttendanceReportCsv(array $report): ?string
{
    $tempDir = sys_get_temp_dir();
    if (!$tempDir || !is_writable($tempDir)) {
        $tempDir = __DIR__ . '/../uploads/temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            error_log('buildMonthlyAttendanceReportCsv: temp directory unavailable');
            return null;
        }
    }

    $filePath = rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . sprintf('attendance_report_%s_%s.csv', $report['month_key'], uniqid());

    $handle = @fopen($filePath, 'w');
    if ($handle === false) {
        error_log('buildMonthlyAttendanceReportCsv: unable to open file for writing');
        return null;
    }

    // كتابة BOM لدعم اللغة العربية في Excel
    fwrite($handle, "\xEF\xBB\xBF");

    $headers = [
        'الموظف',
        'الدور',
        'أيام الحضور',
        'أيام التأخير',
        'إجمالي التأخير (دقائق)',
        'متوسط التأخير (دقائق)',
        'إجمالي الساعات',
        'الراتب المستحق',
        'حالة الراتب',
    ];
    fputcsv($handle, $headers);

    foreach ($report['employees'] as $employee) {
        fputcsv($handle, [
            $employee['name'],
            formatRoleName($employee['role']),
            $employee['attendance_days'],
            $employee['delay_days'],
            number_format($employee['total_delay_minutes'], 2, '.', ''),
            number_format($employee['average_delay_minutes'], 2, '.', ''),
            number_format($employee['total_hours'], 2, '.', ''),
            number_format($employee['salary_amount'], 2, '.', ''),
            $employee['salary_status'],
        ]);
    }

    fclose($handle);
    return $filePath;
}

/**
 * إرسال تقرير الحضور والتأخير الشهري إلى Telegram
 *
 * @param array<string,mixed> $options force=>bool, triggered_by=>int|null, include_csv=>bool
 * @return array{success:bool,message:string}
 */
function sendMonthlyAttendanceReportToTelegram(int $month, int $year, array $options = []): array
{
    if (!isTelegramConfigured()) {
        return [
            'success' => false,
            'message' => 'Telegram bot غير مهيأ',
        ];
    }

    $forceSend   = $options['force'] ?? false;
    $triggeredBy = $options['triggered_by'] ?? null;
    $includeCsv  = $options['include_csv'] ?? true;

    $parts = resolveAttendanceMonthParts($month, $year);
    $monthKey = $parts['month_key'];

    if (!$forceSend) {
        $today = date('Y-m-d');
        $lastDay = date('Y-m-t', strtotime($today));
        if ($today !== $lastDay || hasAttendanceMonthlyReportBeenSent($monthKey, 'telegram_auto')) {
            return [
                'success' => false,
                'message' => 'ليس آخر يوم في الشهر أو تم الإرسال مسبقاً',
            ];
        }
    }

    $report = getMonthlyAttendanceDelayReport($parts['month'], $parts['year']);

    if (empty($report['employees'])) {
        return [
            'success' => false,
            'message' => 'لا توجد سجلات حضور لهذا الشهر',
        ];
    }

    $monthName = date('F', mktime(0, 0, 0, $report['month'], 1));
    $headerLines = [
        "📊 <b>تقرير الحضور الشهري</b>",
        "📅 <b>الشهر:</b> {$monthName} {$report['year']}",
        "👥 <b>عدد الموظفين:</b> {$report['total_employees']}",
        "⏱️ <b>إجمالي الساعات:</b> " . number_format($report['total_hours'], 2) . " ساعة",
        "⏳ <b>إجمالي التأخيرات:</b> " . number_format($report['total_delay_minutes'], 2) . " دقيقة",
        "⏳ <b>متوسط التأخير:</b> " . number_format($report['average_delay_minutes'], 2) . " دقيقة",
        "💰 <b>مجموع الرواتب المستحقة:</b> " . number_format($report['total_salary_amount'], 2)
    ];

    // إبراز أعلى 5 حالات تأخير
    $topEmployees = $report['employees'];
    usort($topEmployees, static function ($a, $b) {
        return $b['total_delay_minutes'] <=> $a['total_delay_minutes'];
    });
    $topEmployees = array_slice($topEmployees, 0, min(5, count($topEmployees)));

    if (!empty($topEmployees)) {
        $headerLines[] = "\n🏅 <b>أعلى حالات التأخير:</b>";
        foreach ($topEmployees as $employee) {
            $headerLines[] = sprintf(
                "• %s (%s) — تأخير كلي: %s دقيقة | متوسط: %s دقيقة | ساعات: %s",
                $employee['name'],
                formatRoleName($employee['role']),
                number_format($employee['total_delay_minutes'], 2),
                number_format($employee['average_delay_minutes'], 2),
                number_format($employee['total_hours'], 2)
            );
        }
    }

    $headerLines[] = "\nتم إرفاق ملف CSV بالتفاصيل الكاملة.";

    $message = implode("\n", $headerLines);

    $sendResult = sendTelegramMessage($message);
    if ($sendResult === false) {
        return [
            'success' => false,
            'message' => 'تعذر إرسال الرسالة إلى Telegram',
        ];
    }

    $csvPath = null;
    if ($includeCsv) {
        $csvPath = buildMonthlyAttendanceReportCsv($report);
        if ($csvPath) {
            $caption = "تقرير الحضور - {$monthName} {$report['year']}";
            sendTelegramFile($csvPath, $caption);
            // حذف الملف المؤقت
            if (file_exists($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    $logChannel = $forceSend ? 'telegram_manual' : 'telegram_auto';
    markAttendanceMonthlyReportSent($monthKey, $logChannel, $report, $triggeredBy);

    return [
        'success' => true,
        'message' => 'تم إرسال تقرير الحضور إلى Telegram بنجاح',
    ];
}

/**
 * إرسال التقرير الشهري تلقائياً إذا كان اليوم هو آخر يوم في الشهر
 */
function maybeSendMonthlyAttendanceTelegramReport(int $month, int $year): void
{
    $today = date('Y-m-d');
    $lastDay = date('Y-m-t', strtotime($today));

    if ($today !== $lastDay) {
        return;
    }

    $parts = resolveAttendanceMonthParts($month, $year);
    if (hasAttendanceMonthlyReportBeenSent($parts['month_key'], 'telegram_auto')) {
        return;
    }

    $result = sendMonthlyAttendanceReportToTelegram($parts['month'], $parts['year'], [
        'force' => false,
    ]);

    if (!$result['success']) {
        error_log('maybeSendMonthlyAttendanceTelegramReport failed: ' . $result['message']);
    }
}

/**
 * التأكد من وجود عمود warning_count في جدول users
 */
function ensureWarningCountColumn(): void
{
    static $ensured = false;
    
    if ($ensured) {
        return;
    }
    
    try {
        $db = db();
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'warning_count'");
        
        if (empty($columnCheck)) {
            $db->execute("ALTER TABLE users ADD COLUMN `warning_count` int(11) DEFAULT 0 AFTER `status`");
            error_log('Added warning_count column to users table');
        }
        
        $ensured = true;
    } catch (Exception $e) {
        error_log('Failed to ensure warning_count column: ' . $e->getMessage());
    }
}

/**
 * التأكد من وجود عمود delay_count في جدول users لتخزين عداد تأخيرات الحضور الشهري
 */
function ensureDelayCountColumn(): void
{
    static $ensured = false;
    
    if ($ensured) {
        return;
    }
    
    try {
        $db = db();
        $columnCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'delay_count'");
        
        if (empty($columnCheck)) {
            $db->execute("ALTER TABLE users ADD COLUMN `delay_count` int(11) DEFAULT 0 AFTER `warning_count`");
            error_log('Added delay_count column to users table');
        }
        
        $ensured = true;
    } catch (Exception $e) {
        error_log('Failed to ensure delay_count column: ' . $e->getMessage());
    }
}

/**
 * تصفير عداد الإنذارات لجميع الموظفين مع بداية كل شهر جديد
 */
function resetWarningCountsForNewMonth(): void
{
    try {
        $db = db();
        $today = date('Y-m-d');
        $firstDayOfMonth = date('Y-m-01');
        
        // التحقق من أن اليوم هو أول يوم في الشهر
        if ($today !== $firstDayOfMonth) {
            return;
        }
        
        // التحقق من أن التصفير لم يتم بالفعل في هذا الشهر
        // استخدام جدول بسيط أو متغير في الجلسة
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'system_settings'");
        
        if (!empty($tableCheck)) {
            $lastResetCheck = $db->queryOne(
                "SELECT `value` FROM system_settings WHERE `key` = 'last_warning_reset_date' LIMIT 1"
            );
            
            if ($lastResetCheck && $lastResetCheck['value'] === $today) {
                return; // تم التصفير بالفعل اليوم
            }
        }
        
        // تصفير عداد الإنذارات وعداد التأخيرات لجميع الموظفين
        ensureWarningCountColumn();
        ensureDelayCountColumn();
        $db->execute("UPDATE users SET warning_count = 0, delay_count = 0 WHERE role != 'manager'");
        
        // حفظ تاريخ آخر تصفير
        if (!empty($tableCheck)) {
            $db->execute(
                "INSERT INTO system_settings (`key`, `value`, updated_at) 
                 VALUES ('last_warning_reset_date', ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()",
                [$today]
            );
        }
        
        error_log('Warning counts and delay counts reset for new month: ' . $today);
    } catch (Exception $e) {
        error_log('Failed to reset warning counts: ' . $e->getMessage());
    }
}

/**
 * التحقق من الموظفين الذين لم يسجلوا انصراف بعد 4 ساعات من وقت الانصراف الرسمي
 * وإجراء الانصراف التلقائي
 */
function processAutoCheckoutForMissingEmployees(): void
{
    try {
        $db = db();
        ensureWarningCountColumn();
        
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $nowTimestamp = strtotime($now);
        
        // الحصول على جميع الموظفين النشطين (ما عدا المديرين)
        $employees = $db->query(
            "SELECT id, full_name, username, role FROM users 
             WHERE status = 'active' AND role != 'manager'"
        );
        
        foreach ($employees as $employee) {
            $userId = (int)$employee['id'];
            
            // الحصول على موعد العمل الرسمي
            $workTime = getOfficialWorkTime($userId);
            if (!$workTime) {
                continue; // لا يوجد موعد عمل لهذا الموظف
            }
            
            // حساب وقت الانصراف الرسمي
            $officialCheckoutTime = $today . ' ' . $workTime['end'];
            $officialCheckoutTimestamp = strtotime($officialCheckoutTime);
            
            // التحقق من أن الوقت الحالي بعد وقت الانصراف الرسمي بأكثر من 4 ساعات
            $hoursSinceOfficialCheckout = ($nowTimestamp - $officialCheckoutTimestamp) / 3600;
            
            if ($hoursSinceOfficialCheckout < 4) {
                continue; // لم يمر 4 ساعات بعد
            }
            
            // التحقق من وجود تسجيل حضور بدون انصراف
            $attendanceRecord = $db->queryOne(
                "SELECT id, check_in_time, date FROM attendance_records 
                 WHERE user_id = ? AND date = ? AND check_out_time IS NULL 
                 ORDER BY check_in_time DESC LIMIT 1",
                [$userId, $today]
            );
            
            if (!$attendanceRecord) {
                continue; // لا يوجد تسجيل حضور بدون انصراف
            }
            
            // التحقق من أن الانصراف التلقائي لم يتم بالفعل
            $autoCheckoutCheck = $db->queryOne(
                "SELECT id FROM attendance_records 
                 WHERE id = ? AND check_out_time IS NOT NULL",
                [$attendanceRecord['id']]
            );
            
            if ($autoCheckoutCheck) {
                continue; // تم تسجيل الانصراف بالفعل
            }
            
            // تسجيل الانصراف التلقائي
            $autoCheckoutTime = date('Y-m-d H:i:s');
            $workHours = calculateWorkHours($attendanceRecord['check_in_time'], $autoCheckoutTime);
            
            // حساب الفرق بين وقت الانصراف الرسمي ووقت الانصراف التلقائي
            // الفرق = وقت الانصراف التلقائي - وقت الانصراف الرسمي (بالساعات)
            $officialCheckoutTimestamp = strtotime($officialCheckoutTime);
            $autoCheckoutTimestamp = strtotime($autoCheckoutTime);
            $hoursDifference = ($autoCheckoutTimestamp - $officialCheckoutTimestamp) / 3600;
            
            // التأكد من أن الفرق موجب (الانصراف التلقائي بعد الانصراف الرسمي)
            if ($hoursDifference < 0) {
                $hoursDifference = 0;
            }
            
            // تحديث سجل الحضور
            $db->execute(
                "UPDATE attendance_records 
                 SET check_out_time = ?, work_hours = ?, updated_at = NOW() 
                 WHERE id = ?",
                [$autoCheckoutTime, $workHours, $attendanceRecord['id']]
            );
            
            // خصم الفرق من الساعات التراكمية الشهرية
            $attendanceDate = $attendanceRecord['date'];
            $attendanceMonthKey = date('Y-m', strtotime($attendanceDate));
            
            // الحصول على الساعات الحالية للشهر
            $currentMonthHours = calculateMonthHours($userId, $attendanceMonthKey);
            
            // خصم الفرق (إذا كان الفرق موجباً)
            if ($hoursDifference > 0) {
                // نحسب الساعات بعد الخصم
                $adjustedHours = max(0, $currentMonthHours - $hoursDifference);
                
                // نحتاج لتعديل سجل الحضور ليعكس الخصم
                // سنقوم بخصم الفرق من work_hours في السجل الحالي
                $adjustedWorkHours = max(0, $workHours - $hoursDifference);
                
                $db->execute(
                    "UPDATE attendance_records 
                     SET work_hours = ? 
                     WHERE id = ?",
                    [$adjustedWorkHours, $attendanceRecord['id']]
                );
                
                error_log("Auto checkout: User {$userId}, deducted {$hoursDifference} hours from monthly total");
            }
            
            // زيادة عداد الإنذارات
            $currentWarningCount = (int)$db->queryOne(
                "SELECT warning_count FROM users WHERE id = ?",
                [$userId]
            )['warning_count'] ?? 0;
            
            $newWarningCount = $currentWarningCount + 1;
            $db->execute(
                "UPDATE users SET warning_count = ? WHERE id = ?",
                [$newWarningCount, $userId]
            );
            
            // إرسال إنذار للموظف
            $userName = $employee['full_name'] ?? $employee['username'];
            $message = "تم تسجيل انصراف تلقائي لك لأنك لم تسجل انصرافك بعد مرور 4 ساعات على وقت الانصراف الرسمي. يرجى عدم نسيان تسجيل الانصراف في المستقبل.";
            
            createNotification(
                $userId,
                'إنذار: نسيان تسجيل الانصراف',
                $message,
                'warning',
                getAttendanceReminderLink($employee['role']),
                true // إرسال عبر Telegram
            );
            
            // إذا وصل عداد الإنذارات إلى 3 أو أكثر، خصم ساعتين إضافيتين
            if ($newWarningCount >= 3) {
                $monthHours = calculateMonthHours($userId, $attendanceMonthKey);
                $adjustedMonthHours = max(0, $monthHours - 2);
                
                // نحتاج لخصم ساعتين من أحد سجلات الحضور في الشهر
                // سنخصم من آخر سجل حضور في الشهر
                $lastRecord = $db->queryOne(
                    "SELECT id, work_hours FROM attendance_records 
                     WHERE user_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
                     AND check_out_time IS NOT NULL 
                     ORDER BY date DESC, check_out_time DESC LIMIT 1",
                    [$userId, $attendanceMonthKey]
                );
                
                if ($lastRecord) {
                    $lastRecordHours = (float)($lastRecord['work_hours'] ?? 0);
                    $adjustedLastRecordHours = max(0, $lastRecordHours - 2);
                    
                    $db->execute(
                        "UPDATE attendance_records 
                         SET work_hours = ? 
                         WHERE id = ?",
                        [$adjustedLastRecordHours, $lastRecord['id']]
                    );
                    
                    error_log("Auto checkout: User {$userId} reached 3+ warnings, deducted 2 additional hours");
                }
            }
            
            error_log("Auto checkout processed for user {$userId} at {$autoCheckoutTime}");
        }
    } catch (Exception $e) {
        error_log('Failed to process auto checkout: ' . $e->getMessage());
    }
}

