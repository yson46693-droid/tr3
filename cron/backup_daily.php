<?php
/**
 * سكريبت النسخ الاحتياطي اليومي
 * يمكن تشغيله عبر Cron Job أو Task Scheduler
 * 
 * مثال على Cron Job:
 * 0 2 * * * /usr/bin/php /path/to/cron/backup_daily.php
 * (يعمل كل يوم الساعة 2 صباحاً)
 */

// تعيين المسار
define('BASE_PATH', dirname(__DIR__));
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup.php';

// إنشاء نسخة احتياطية يومية
$result = createDatabaseBackup('daily', null);

if ($result['success']) {
    echo "Backup created successfully: " . $result['filename'] . "\n";
    echo "File size: " . formatFileSize($result['file_size']) . "\n";
} else {
    echo "Backup failed: " . $result['message'] . "\n";
    exit(1);
}

