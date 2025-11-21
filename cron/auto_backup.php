<?php
/**
 * سكريبت النسخ الاحتياطي التلقائي اليومي
 * يمكن تشغيله عبر Cron Job أو استدعاؤه من صفحة المدير
 */

// تعيين المسار
define('BASE_PATH', dirname(__DIR__));
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup.php';

// التحقق من آخر نسخة احتياطية
$db = db();
$lastBackup = $db->queryOne(
    "SELECT created_at FROM backups 
     WHERE backup_type = 'daily' AND status = 'success'
     ORDER BY created_at DESC LIMIT 1"
);

// إذا كان آخر نسخة من اليوم، لا نحتاج لإنشاء واحدة جديدة
if ($lastBackup && date('Y-m-d', strtotime($lastBackup['created_at'])) === date('Y-m-d')) {
    echo "Backup already exists for today.\n";
    exit(0);
}

// إنشاء نسخة احتياطية يومية
$result = createDatabaseBackup('daily', null);

if ($result['success']) {
    echo "Daily backup created successfully: " . $result['filename'] . "\n";
    echo "File size: " . formatFileSize($result['file_size']) . "\n";
    exit(0);
} else {
    echo "Backup failed: " . $result['message'] . "\n";
    exit(1);
}

