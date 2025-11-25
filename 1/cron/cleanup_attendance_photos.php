<?php
/**
 * سكربت تنظيف صور الحضور والانصراف القديمة
 * يحذف الصور التي مر عليها أكثر من 30 يوم
 */

// منع الوصول المباشر إلا من خلال cron أو CLI
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_token'])) {
    http_response_code(403);
    die('Forbidden');
}

// التحقق من token إذا كان الوصول عبر HTTP
$validToken = 'cleanup_attendance_photos_' . date('Ymd');
if (php_sapi_name() !== 'cli' && (!isset($_GET['cron_token']) || $_GET['cron_token'] !== md5($validToken))) {
    http_response_code(403);
    die('Invalid token');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attendance.php';

/**
 * حذف صور الحضور والانصراف القديمة
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
            error_log('cleanup_attendance_photos: uploads directory not found');
            return $stats;
        }
    }

    $attendanceDir = $uploadsRoot . DIRECTORY_SEPARATOR . 'attendance';
    if (!is_dir($attendanceDir)) {
        error_log('cleanup_attendance_photos: attendance directory not found');
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
                            error_log("cleanup_attendance_photos: Failed to delete file: {$file}");
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
                            error_log("cleanup_attendance_photos: Failed to delete file: {$file}");
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
                    $updateValues = [];

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
                error_log('cleanup_attendance_photos: Database update error: ' . $e->getMessage());
                $stats['errors']++;
            }
        }

    } catch (Exception $e) {
        error_log('cleanup_attendance_photos: Error: ' . $e->getMessage());
        $stats['errors']++;
    }

    return $stats;
}

// تشغيل عملية التنظيف
$daysOld = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$stats = cleanupOldAttendancePhotos($daysOld);

// تسجيل النتائج
$message = sprintf(
    "Attendance photos cleanup completed: %d files deleted, %d folders deleted, %.2f MB freed, %d errors",
    $stats['deleted_files'],
    $stats['deleted_folders'],
    $stats['total_size_freed'] / (1024 * 1024),
    $stats['errors']
);

error_log($message);

// إرجاع النتائج إذا كان الوصول عبر HTTP
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => $message,
        'stats' => $stats
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} else {
    echo $message . PHP_EOL;
}

