<?php
/**
 * مراقبة استخدام النظام
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!function_exists('db')) {
    require_once __DIR__ . '/db.php';
}

/**
 * التأكد من وجود جداول المراقبة
 */
function ensureRequestUsageTables() {
    static $tablesEnsured = false;
    if ($tablesEnsured) {
        return;
    }

    $db = db();

    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `request_usage` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `user_id` INT(11) DEFAULT NULL,
              `ip_address` VARCHAR(45) NOT NULL,
              `method` VARCHAR(10) NOT NULL,
              `path` TEXT DEFAULT NULL,
              `user_agent` VARCHAR(255) DEFAULT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_user_date` (`user_id`, `created_at`),
              KEY `idx_ip_date` (`ip_address`, `created_at`),
              KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->execute("
            CREATE TABLE IF NOT EXISTS `request_usage_alerts` (
              `id` INT(11) NOT NULL AUTO_INCREMENT,
              `identifier_type` ENUM('user','ip') NOT NULL,
              `identifier_value` VARCHAR(255) NOT NULL,
              `window_start` DATETIME NOT NULL,
              `window_end` DATETIME NOT NULL,
              `request_count` INT(11) NOT NULL,
              `threshold` INT(11) NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_identifier_window` (`identifier_type`, `identifier_value`, `window_start`, `window_end`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('Request usage table ensure error: ' . $e->getMessage());
    }

    $tablesEnsured = true;
}

/**
 * تسجيل الطلب الحالي في جدول الاستخدام
 */
function logRequestUsage($pathOverride = null) {
    if (defined('REQUEST_USAGE_MONITOR_ENABLED') && REQUEST_USAGE_MONITOR_ENABLED === false) {
        return;
    }

    if (php_sapi_name() === 'cli') {
        return;
    }

    if (!isset($_SERVER['REMOTE_ADDR'])) {
        return;
    }

    static $logged = false;
    if ($logged) {
        return;
    }
    $logged = true;

    try {
        ensureRequestUsageTables();
        $db = db();

        $userId = $_SESSION['user_id'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = $pathOverride ?? ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'unknown'));
        if ($path === '' || $path === null) {
            $path = 'unknown';
        }
        $path = mb_substr($path, 0, 500);

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($userAgent !== null) {
            $userAgent = mb_substr($userAgent, 0, 255);
        }

        $db->execute(
            "INSERT INTO request_usage (user_id, ip_address, method, path, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $userId ? intval($userId) : null,
                $ipAddress,
                $method,
                $path,
                $userAgent,
            ]
        );

        $windowMinutes = defined('REQUEST_USAGE_ALERT_WINDOW_MINUTES') ? intval(REQUEST_USAGE_ALERT_WINDOW_MINUTES) : 1440;
        if ($windowMinutes <= 0) {
            $windowMinutes = 1440;
        }
        if ($windowMinutes >= 1440) {
            $windowStart = date('Y-m-d 00:00:00');
            $windowEnd = date('Y-m-d 23:59:59');
        } else {
            $windowStart = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
            $windowEnd = date('Y-m-d H:i:s');
        }

        if ($userId && defined('REQUEST_USAGE_THRESHOLD_PER_USER') && REQUEST_USAGE_THRESHOLD_PER_USER > 0) {
            $userCount = $db->queryOne(
                "SELECT COUNT(*) AS total
                 FROM request_usage
                 WHERE user_id = ? AND created_at BETWEEN ? AND ?",
                [$userId, $windowStart, $windowEnd]
            );
            $total = intval($userCount['total'] ?? 0);
            if ($total >= REQUEST_USAGE_THRESHOLD_PER_USER) {
                recordRequestUsageAlert(
                    'user',
                    (string) $userId,
                    $windowStart,
                    $windowEnd,
                    $total,
                    REQUEST_USAGE_THRESHOLD_PER_USER
                );
            }
        }

        if (!empty($ipAddress) && defined('REQUEST_USAGE_THRESHOLD_PER_IP') && REQUEST_USAGE_THRESHOLD_PER_IP > 0) {
            $ipCount = $db->queryOne(
                "SELECT COUNT(*) AS total
                 FROM request_usage
                 WHERE ip_address = ? AND created_at BETWEEN ? AND ?",
                [$ipAddress, $windowStart, $windowEnd]
            );
            $total = intval($ipCount['total'] ?? 0);
            if ($total >= REQUEST_USAGE_THRESHOLD_PER_IP) {
                recordRequestUsageAlert(
                    'ip',
                    $ipAddress,
                    $windowStart,
                    $windowEnd,
                    $total,
                    REQUEST_USAGE_THRESHOLD_PER_IP
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Request usage log error: ' . $e->getMessage());
    }
}

/**
 * إنشاء أو تحديث تنبيه الاستخدام المرتفع
 */
function recordRequestUsageAlert($identifierType, $identifierValue, $windowStart, $windowEnd, $count, $threshold) {
    try {
        ensureRequestUsageTables();
        $db = db();
        $db->execute(
            "INSERT INTO request_usage_alerts (identifier_type, identifier_value, window_start, window_end, request_count, threshold)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                request_count = VALUES(request_count),
                threshold = VALUES(threshold),
                updated_at = NOW()",
            [
                $identifierType,
                $identifierValue,
                $windowStart,
                $windowEnd,
                $count,
                $threshold,
            ]
        );
    } catch (Throwable $e) {
        error_log('Request usage alert error: ' . $e->getMessage());
    }
}

/**
 * الحصول على ملخص الاستخدام اليومي (حسب المستخدم أو IP)
 */
function getRequestUsageSummary($options = []) {
    $type = $options['type'] ?? 'user';
    $date = $options['date'] ?? date('Y-m-d');
    $limit = isset($options['limit']) ? max(1, intval($options['limit'])) : 50;

    try {
        ensureRequestUsageTables();
        $db = db();
        $start = date('Y-m-d 00:00:00', strtotime($date));
        $end = date('Y-m-d 23:59:59', strtotime($date));

        if ($type === 'ip') {
            return $db->query(
                "SELECT 
                    ru.ip_address,
                    COUNT(*) AS total_requests,
                    SUM(CASE WHEN ru.user_id IS NULL THEN 1 ELSE 0 END) AS anonymous_requests,
                    MAX(ru.created_at) AS last_request,
                    MIN(ru.created_at) AS first_request,
                    GROUP_CONCAT(DISTINCT u.username ORDER BY u.username SEPARATOR ', ') AS usernames
                 FROM request_usage ru
                 LEFT JOIN users u ON ru.user_id = u.id
                 WHERE ru.created_at BETWEEN ? AND ?
                 GROUP BY ru.ip_address
                 ORDER BY total_requests DESC
                 LIMIT ?",
                [$start, $end, $limit]
            );
        }

        return $db->query(
            "SELECT 
                ru.user_id,
                u.username,
                u.full_name,
                u.role,
                COUNT(*) AS total_requests,
                MAX(ru.created_at) AS last_request,
                MIN(ru.created_at) AS first_request,
                GROUP_CONCAT(DISTINCT ru.ip_address SEPARATOR ', ') AS recent_ips
             FROM request_usage ru
             LEFT JOIN users u ON ru.user_id = u.id
             WHERE ru.user_id IS NOT NULL
               AND ru.created_at BETWEEN ? AND ?
             GROUP BY ru.user_id, u.username, u.full_name, u.role
             ORDER BY total_requests DESC
             LIMIT ?",
            [$start, $end, $limit]
        );
    } catch (Throwable $e) {
        error_log('Request usage summary error: ' . $e->getMessage());
        return [];
    }
}

/**
 * الحصول على تفاصيل الاستخدام لمستخدم محدد
 */
function getRequestUsageDetailsForUser($userId, $date = null) {
    $date = $date ?? date('Y-m-d');

    try {
        ensureRequestUsageTables();
        $db = db();
        $start = date('Y-m-d 00:00:00', strtotime($date));
        $end = date('Y-m-d 23:59:59', strtotime($date));

        return $db->query(
            "SELECT path, method, COUNT(*) AS total_requests, MAX(created_at) AS last_request
             FROM request_usage
             WHERE user_id = ?
               AND created_at BETWEEN ? AND ?
             GROUP BY path, method
             ORDER BY total_requests DESC
             LIMIT 20",
            [$userId, $start, $end]
        );
    } catch (Throwable $e) {
        error_log('Request usage details error: ' . $e->getMessage());
        return [];
    }
}

/**
 * الحصول على تنبيهات الاستخدام العالي
 */
function getRequestUsageAlerts($date = null, $limit = 50) {
    $date = $date ?? date('Y-m-d');
    $limit = max(1, intval($limit));

    try {
        ensureRequestUsageTables();
        $db = db();
        $start = date('Y-m-d 00:00:00', strtotime($date));
        $end = date('Y-m-d 23:59:59', strtotime($date));

        return $db->query(
            "SELECT *
             FROM request_usage_alerts
             WHERE window_end >= ? AND window_start <= ?
             ORDER BY request_count DESC
             LIMIT ?",
            [$start, $end, $limit]
        );
    } catch (Throwable $e) {
        error_log('Request usage alert fetch error: ' . $e->getMessage());
        return [];
    }
}

/**
 * تنظيف سجلات الاستخدام القديمة
 */
function cleanupRequestUsage($days = 30) {
    $days = max(1, intval($days));

    try {
        ensureRequestUsageTables();
        $db = db();

        $batchLimit = 5000;
        $deletedUsage = 0;
        $deletedAlerts = 0;

        do {
            $result = $db->execute(
                "DELETE FROM request_usage 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 LIMIT {$batchLimit}"
            );

            $batchDeleted = intval($result['affected_rows'] ?? 0);
            $deletedUsage += $batchDeleted;
        } while ($batchDeleted === $batchLimit && $batchDeleted > 0);

        do {
            $alertsResult = $db->execute(
                "DELETE FROM request_usage_alerts 
                 WHERE window_end < DATE_SUB(NOW(), INTERVAL {$days} DAY)
                 LIMIT {$batchLimit}"
            );

            $batchDeletedAlerts = intval($alertsResult['affected_rows'] ?? 0);
            $deletedAlerts += $batchDeletedAlerts;
        } while ($batchDeletedAlerts === $batchLimit && $batchDeletedAlerts > 0);

        return $deletedUsage + $deletedAlerts;
    } catch (Throwable $e) {
        error_log('Request usage cleanup error: ' . $e->getMessage());
        return 0;
    }
}

