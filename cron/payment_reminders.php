<?php
/**
 * Cron Job لإرسال تذكيرات التحصيل التلقائية
 * يجب تشغيله يومياً (يفضل كل ساعة)
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/payment_schedules.php';
require_once __DIR__ . '/../includes/notifications.php';

// تحديث الحالات المتأخرة
updateOverdueSchedules();

// إرسال التذكيرات
$sentCount = sendPaymentReminders();

// إنشاء تذكيرات تلقائية للجداول القادمة (3 أيام قبل الاستحقاق)
$db = db();
$pendingSchedules = $db->query(
    "SELECT id FROM payment_schedules 
     WHERE status = 'pending' 
     AND due_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
     AND reminder_sent = 0"
);

$createdCount = 0;
foreach ($pendingSchedules as $schedule) {
    if (createAutoReminder($schedule['id'], 3)) {
        $createdCount++;
    }
}

echo "تم إرسال {$sentCount} تذكير وإنشاء {$createdCount} تذكير جديد\n";

