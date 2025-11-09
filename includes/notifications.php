<?php
/**
 * نظام الإشعارات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_notifications.php';
require_once __DIR__ . '/path_helper.php';
if (!function_exists('getOfficialWorkTime')) {
    require_once __DIR__ . '/attendance.php';
}

/**
 * إنشاء إشعار جديد
 */
function createNotification($userId, $title, $message, $type = 'info', $link = null, $sendTelegram = false) {
    try {
        $db = db();
        
        $sql = "INSERT INTO notifications (user_id, title, message, type, link) 
                VALUES (?, ?, ?, ?, ?)";
        
        $db->execute($sql, [
            $userId,
            $title,
            $message,
            $type,
            $link
        ]);
        
        // إرسال إشعار Telegram إذا كان مفعّل
        if ($sendTelegram && isTelegramConfigured()) {
            $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
            if ($user) {
                $telegramMessage = "📢 <b>{$title}</b>\n\n{$message}";
                if ($link) {
                    $telegramMessage .= "\n\n🔗 رابط: {$link}";
                }
                sendTelegramNotificationByRole($user['role'], $telegramMessage, $type);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إنشاء إشعار لجميع المستخدمين بدور معين
 */
function createNotificationForRole($role, $title, $message, $type = 'info', $link = null, $sendTelegram = false) {
    try {
        $db = db();
        
        $users = $db->query("SELECT id FROM users WHERE role = ? AND status = 'active'", [$role]);
        
        foreach ($users as $user) {
            createNotification($user['id'], $title, $message, $type, $link, false);
        }
        
        // إرسال إشعار Telegram للدور إذا كان مفعّل
        if ($sendTelegram && isTelegramConfigured()) {
            $telegramMessage = "📢 <b>{$title}</b>\n\n{$message}";
            if ($link) {
                $telegramMessage .= "\n\n🔗 رابط: {$link}";
            }
            sendTelegramNotificationByRole($role, $telegramMessage, $type);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إنشاء إشعار لجميع المديرين
 */
function notifyManagers($title, $message, $type = 'info', $link = null, $sendTelegram = true) {
    return createNotificationForRole('manager', $title, $message, $type, $link, $sendTelegram);
}

/**
 * الحصول على إشعارات المستخدم
 */
function getUserNotifications($userId, $unreadOnly = false, $limit = 50) {
    $db = db();
    
    $sql = "SELECT * FROM notifications 
            WHERE user_id = ?";
    
    if ($unreadOnly) {
        $sql .= " AND `read` = 0";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    return $db->query($sql, [$userId, $limit]);
}

/**
 * الحصول على عدد الإشعارات غير المقروءة
 */
function getUnreadNotificationCount($userId) {
    $db = db();
    
    $result = $db->queryOne(
        "SELECT COUNT(*) as count FROM notifications 
         WHERE user_id = ? AND `read` = 0",
        [$userId]
    );
    
    return $result['count'] ?? 0;
}

/**
 * تحديد إشعار كمقروء
 */
function markNotificationAsRead($notificationId, $userId) {
    $db = db();
    
    $db->execute(
        "UPDATE notifications SET `read` = 1 
         WHERE id = ? AND user_id = ?",
        [$notificationId, $userId]
    );
    
    return true;
}

/**
 * تحديد جميع الإشعارات كمقروءة
 */
function markAllNotificationsAsRead($userId) {
    $db = db();
    
    $db->execute(
        "UPDATE notifications SET `read` = 1 
         WHERE user_id = ? AND `read` = 0",
        [$userId]
    );
    
    return true;
}

/**
 * حذف إشعار
 */
function deleteNotification($notificationId, $userId) {
    $db = db();
    
    $db->execute(
        "DELETE FROM notifications WHERE id = ? AND user_id = ?",
        [$notificationId, $userId]
    );
    
    return true;
}

/**
 * إرسال إشعار متصفح (Browser Notification)
 */
function sendBrowserNotification($title, $body, $icon = null, $tag = null) {
    // يتم إرسال إشعارات المتصفح عبر JavaScript
    // هذه الدالة للإشارة فقط
    return true;
}

/**
 * الحصول على رابط صفحة الحضور المناسب للدور
 */
function getAttendanceReminderLink($role) {
    $dashboardUrl = getDashboardUrl($role);
    $separator = strpos($dashboardUrl, '?') === false ? '?' : '&';
    return $dashboardUrl . $separator . 'page=attendance';
}

/**
 * إنشاء أو تحديث تذكير الحضور/الانصراف
 */
function ensureAttendanceReminderForUser($userId, $role, $kind, $title, $message) {
    if (!$userId || !in_array($kind, ['checkin', 'checkout'], true)) {
        return false;
    }

    $db = db();
    $type = 'attendance_' . $kind;
    $link = getAttendanceReminderLink($role);

    $existing = $db->queryOne(
        "SELECT id, `read`, created_at FROM notifications WHERE user_id = ? AND type = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 1",
        [$userId, $type]
    );

    if ($existing) {
        $notificationId = (int) ($existing['id'] ?? 0);
        $isUnread = isset($existing['read']) ? ((int) $existing['read'] === 0) : true;
        $shouldReactivate = false;

        if (!$isUnread) {
            $cooldownMinutes = 120;
            $lastCreatedAt = null;

            if (!empty($existing['created_at'])) {
                $lastCreatedAt = DateTime::createFromFormat('Y-m-d H:i:s', $existing['created_at']);
            }

            if ($lastCreatedAt instanceof DateTime) {
                $minutesSince = floor((time() - $lastCreatedAt->getTimestamp()) / 60);
                if ($minutesSince >= $cooldownMinutes) {
                    $shouldReactivate = true;
                }
            } else {
                $shouldReactivate = true;
            }
        }

        $setParts = ['title = ?', 'message = ?', 'link = ?'];
        $params = [$title, $message, $link];

        if ($shouldReactivate) {
            $setParts[] = "`read` = 0";
            $setParts[] = "created_at = NOW()";
        }

        $params[] = $notificationId;

        $db->execute(
            "UPDATE notifications SET " . implode(', ', $setParts) . " WHERE id = ?",
            $params
        );

        return $notificationId;
    }

    return createNotification($userId, $title, $message, $type, $link, false);
}

/**
 * إزالة تذكير الحضور/الانصراف للمستخدم
 */
function clearAttendanceReminderForUser($userId, $kind) {
    if (!$userId || !in_array($kind, ['checkin', 'checkout'], true)) {
        return false;
    }

    $db = db();
    $type = 'attendance_' . $kind;

    $db->execute(
        "DELETE FROM notifications WHERE user_id = ? AND type = ?",
        [$userId, $type]
    );

    return true;
}

/**
 * التعامل مع تذكيرات الحضور/الانصراف للمستخدم الحالي
 */
function handleAttendanceRemindersForUser($user) {
    if (empty($user) || empty($user['id']) || empty($user['role'])) {
        return;
    }

    $role = $user['role'];
    if (!in_array($role, ['production', 'sales', 'accountant'], true)) {
        return;
    }

    $userId = (int) $user['id'];
    $workTime = getOfficialWorkTime($userId);
    if (!$workTime || empty($workTime['start']) || empty($workTime['end'])) {
        return;
    }

    $now = new DateTime('now');
    $today = $now->format('Y-m-d');

    $startTime = DateTime::createFromFormat('Y-m-d H:i:s', "{$today} {$workTime['start']}");
    $endTime = DateTime::createFromFormat('Y-m-d H:i:s', "{$today} {$workTime['end']}");

    if (!$startTime || !$endTime) {
        return;
    }

    $checkInRecords = getTodayAttendanceRecords($userId, $today);
    $hasCheckIn = !empty($checkInRecords);
    $hasOpenAttendance = false;
    $openAttendanceCheckInTime = null;

    foreach ($checkInRecords as $record) {
        if (empty($record['check_out_time'])) {
            $hasOpenAttendance = true;
            if (!empty($record['check_in_time']) && !$openAttendanceCheckInTime) {
                try {
                    $openAttendanceCheckInTime = new DateTime($record['check_in_time']);
                } catch (Exception $e) {
                    $openAttendanceCheckInTime = null;
                }
            }
            break;
        }
    }

    // تذكير تسجيل الحضور
    $checkInReminderThreshold = (clone $startTime)->modify('-15 minutes');
    if (!$hasCheckIn && $now >= $checkInReminderThreshold) {
        $title = 'تنبيه تسجيل الحضور';
        $message = 'تنبيه هام لتسجيل الحضور لتفادي الخصومات. يرجى تسجيل الحضور الآن.';
        ensureAttendanceReminderForUser($userId, $role, 'checkin', $title, $message);
    } else {
        clearAttendanceReminderForUser($userId, 'checkin');
    }

    // تذكير تسجيل الانصراف
    $checkOutReminderThreshold = (clone $endTime)->modify('-10 minutes');
    $remainingMinutes = (int) floor(($endTime->getTimestamp() - $now->getTimestamp()) / 60);
    $inCheckoutWindow = $remainingMinutes >= 0 && $remainingMinutes <= 10;
    $eligibleForCheckoutReminder = $hasOpenAttendance && $inCheckoutWindow && $now >= $checkOutReminderThreshold;

    if ($eligibleForCheckoutReminder && $openAttendanceCheckInTime instanceof DateTime) {
        $minutesSinceCheckIn = floor(($now->getTimestamp() - $openAttendanceCheckInTime->getTimestamp()) / 60);
        $minimumSessionMinutes = 30;
        if ($minutesSinceCheckIn < $minimumSessionMinutes) {
            $eligibleForCheckoutReminder = false;
        }
    }

    if ($eligibleForCheckoutReminder) {
        $title = 'تنبيه تسجيل الانصراف';
        $message = 'تنبيه هام لتسجيل الانصراف لتفادي الخصومات. يرجى تسجيل الانصراف قبل مغادرة العمل.';
        ensureAttendanceReminderForUser($userId, $role, 'checkout', $title, $message);
    } else {
        clearAttendanceReminderForUser($userId, 'checkout');
    }
}

