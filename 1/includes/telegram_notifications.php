<?php
/**
 * نظام الإشعارات عبر Telegram
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram_config.php';
require_once __DIR__ . '/db.php';

/**
 * إرسال إشعار Telegram حسب الدور
 */
function sendTelegramNotificationByRole($role, $message, $priority = 'normal') {
    if (!isTelegramConfigured()) {
        return false;
    }
    
    $db = db();
    
    // الحصول على المستخدمين الذين لديهم إشعارات Telegram مفعلة لهذا الدور
    $users = $db->query(
        "SELECT u.id, u.full_name, u.username
         FROM users u
         INNER JOIN notification_settings ns ON (ns.user_id = u.id OR ns.role = u.role)
         WHERE u.role = ? AND u.status = 'active' 
         AND ns.telegram_enabled = 1
         AND ns.notification_type = ?",
        [$role, $priority]
    );
    
    $sent = 0;
    foreach ($users as $user) {
        if (sendTelegramMessage($message)) {
            $sent++;
        }
    }
    
    return $sent > 0;
}

/**
 * إرسال إشعار Telegram للمديرين
 */
function sendTelegramToManagers($message, $priority = 'normal') {
    return sendTelegramNotificationByRole('manager', $message, $priority);
}

/**
 * إرسال إشعار تنبيه مهم
 */
function sendCriticalTelegramAlert($title, $message, $details = []) {
    if (!isTelegramConfigured()) {
        return false;
    }
    
    $formattedMessage = "🚨 <b>{$title}</b>\n\n";
    $formattedMessage .= "{$message}\n\n";
    
    if (!empty($details)) {
        $formattedMessage .= "📋 التفاصيل:\n";
        foreach ($details as $key => $value) {
            $formattedMessage .= "• {$key}: {$value}\n";
        }
    }
    
    $formattedMessage .= "\n⏰ " . date('Y-m-d H:i:s');
    
    // إرسال للمديرين
    sendTelegramToManagers($formattedMessage, 'critical');
    
    // إرسال للدور المحدد في الإعدادات
    $db = db();
    $settings = $db->query(
        "SELECT DISTINCT role FROM notification_settings 
         WHERE notification_type = 'critical' AND telegram_enabled = 1"
    );
    
    foreach ($settings as $setting) {
        if ($setting['role'] !== 'manager') {
            sendTelegramNotificationByRole($setting['role'], $formattedMessage, 'critical');
        }
    }
    
    return true;
}

/**
 * إعداد إشعارات Telegram حسب الدور
 */
function setupRoleTelegramNotifications($role, $notificationType, $enabled = true) {
    $db = db();
    
    // حذف الإعدادات السابقة
    $db->execute(
        "DELETE FROM notification_settings WHERE role = ? AND notification_type = ?",
        [$role, $notificationType]
    );
    
    // إضافة إعداد جديد
    $db->execute(
        "INSERT INTO notification_settings (role, notification_type, telegram_enabled) 
         VALUES (?, ?, ?)",
        [$role, $notificationType, $enabled ? 1 : 0]
    );
    
    return true;
}

/**
 * إعداد إشعارات Telegram لمستخدم محدد
 */
function setupUserTelegramNotifications($userId, $notificationType, $enabled = true) {
    $db = db();
    
    // التحقق من وجود إعداد
    $existing = $db->queryOne(
        "SELECT id FROM notification_settings WHERE user_id = ? AND notification_type = ?",
        [$userId, $notificationType]
    );
    
    if ($existing) {
        // تحديث
        $db->execute(
            "UPDATE notification_settings SET telegram_enabled = ? WHERE id = ?",
            [$enabled ? 1 : 0, $existing['id']]
        );
    } else {
        // إنشاء جديد
        $db->execute(
            "INSERT INTO notification_settings (user_id, notification_type, telegram_enabled) 
             VALUES (?, ?, ?)",
            [$userId, $notificationType, $enabled ? 1 : 0]
        );
    }
    
    return true;
}

/**
 * الحصول على إعدادات الإشعارات
 */
function getNotificationSettings($userId = null, $role = null) {
    $db = db();
    
    $sql = "SELECT * FROM notification_settings WHERE 1=1";
    $params = [];
    
    if ($userId) {
        $sql .= " AND user_id = ?";
        $params[] = $userId;
    }
    
    if ($role) {
        $sql .= " AND role = ?";
        $params[] = $role;
    }
    
    return $db->query($sql, $params);
}

