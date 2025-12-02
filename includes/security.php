<?php
/**
 * نظام الأمان
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * تسجيل محاولة تسجيل دخول
 */
function logLoginAttempt($username, $success, $failureReason = null) {
    try {
        $db = db();
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $sql = "INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason) 
                VALUES (?, ?, ?, ?, ?)";
        
        $db->execute($sql, [
            $username,
            $ipAddress,
            $userAgent,
            $success ? 1 : 0,
            $failureReason
        ]);

        enforceLoginAttemptsLimit(50);
        
        // إذا فشلت المحاولة، تحقق من عدد المحاولات الفاشلة
        if (!$success) {
            checkFailedAttempts($ipAddress, $username);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Login Attempt Log Error: " . $e->getMessage());
        return false;
    }
}

/**
 * التحقق من عدد المحاولات الفاشلة
 */
function checkFailedAttempts($ipAddress, $username = null) {
    $db = db();
    
    // التحقق من حظر IP
    if (isIPBlocked($ipAddress)) {
        return true; // IP محظور بالفعل
    }
    
    // عدد المحاولات الفاشلة في آخر 15 دقيقة
    $timeLimit = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    
    $where = "ip_address = ? AND success = 0 AND created_at >= ?";
    $params = [$ipAddress, $timeLimit];
    
    if ($username) {
        $where .= " AND username = ?";
        $params[] = $username;
    }
    
    $result = $db->queryOne(
        "SELECT COUNT(*) as count FROM login_attempts WHERE $where",
        $params
    );
    
    $failedCount = $result['count'] ?? 0;
    
    // إذا تجاوز 5 محاولات فاشلة، حظر IP
    if ($failedCount >= 5) {
        blockIP($ipAddress, 'محاولات تسجيل دخول فاشلة متعددة');
        return true;
    }
    
    return false;
}

/**
 * التحقق من حظر IP
 */
function isIPBlocked($ipAddress) {
    $db = db();
    
    $result = $db->queryOne(
        "SELECT * FROM blocked_ips 
         WHERE ip_address = ? 
         AND (blocked_until IS NULL OR blocked_until > NOW())",
        [$ipAddress]
    );
    
    return !empty($result);
}

/**
 * حظر IP
 */
function blockIP($ipAddress, $reason = null, $blockedUntil = null, $blockedBy = null) {
    try {
        $db = db();
        
        // التحقق من وجود حظر سابق
        $existing = $db->queryOne(
            "SELECT id FROM blocked_ips WHERE ip_address = ?",
            [$ipAddress]
        );
        
        if ($existing) {
            // تحديث الحظر
            $sql = "UPDATE blocked_ips SET reason = ?, blocked_until = ?, blocked_by = ? 
                    WHERE ip_address = ?";
            $db->execute($sql, [$reason, $blockedUntil, $blockedBy, $ipAddress]);
        } else {
            // إنشاء حظر جديد (افتراضي: 1 ساعة)
            if (!$blockedUntil) {
                $blockedUntil = date('Y-m-d H:i:s', strtotime('+1 hour'));
            }
            
            $sql = "INSERT INTO blocked_ips (ip_address, reason, blocked_until, blocked_by) 
                    VALUES (?, ?, ?, ?)";
            $db->execute($sql, [$ipAddress, $reason, $blockedUntil, $blockedBy]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Block IP Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إلغاء حظر IP
 */
function unblockIP($ipAddress) {
    $db = db();
    
    $db->execute(
        "UPDATE blocked_ips SET blocked_until = NOW() WHERE ip_address = ?",
        [$ipAddress]
    );
    
    return true;
}

/**
 * الحصول على محاولات تسجيل الدخول
 */
function getLoginAttempts($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT * FROM login_attempts WHERE 1=1";
    $params = [];
    
    if (!empty($filters['ip_address'])) {
        $sql .= " AND ip_address = ?";
        $params[] = $filters['ip_address'];
    }
    
    if (!empty($filters['username'])) {
        $sql .= " AND username = ?";
        $params[] = $filters['username'];
    }
    
    if (isset($filters['success'])) {
        $sql .= " AND success = ?";
        $params[] = $filters['success'] ? 1 : 0;
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على IP المحظورة
 */
function getBlockedIPs() {
    $db = db();
    
    return $db->query(
        "SELECT bi.*, u.username as blocked_by_name
         FROM blocked_ips bi
         LEFT JOIN users u ON bi.blocked_by = u.id
         WHERE blocked_until IS NULL OR blocked_until > NOW()
         ORDER BY created_at DESC"
    );
}

/**
 * حذف محاولات تسجيل الدخول القديمة
 * @param int $days عدد الأيام - يتم حذف السجلات الأقدم من هذا العدد (افتراضي: 1 يوم)
 * @return array ['success' => bool, 'deleted' => int, 'message' => string]
 */
function cleanupOldLoginAttempts($days = 1) {
    try {
        $db = db();
        
        // تاريخ قبل X يوم
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // التحقق من وجود الجدول أولاً
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'login_attempts'");
        if (empty($tableCheck)) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'جدول محاولات تسجيل الدخول غير موجود'
            ];
        }
        
        // الحصول على عدد السجلات قبل الحذف
        $countResult = $db->queryOne(
            "SELECT COUNT(*) as count FROM login_attempts WHERE created_at < ?",
            [$cutoffDate]
        );
        $countBefore = intval($countResult['count'] ?? 0);
        
        if ($countBefore == 0) {
            return [
                'success' => true,
                'deleted' => 0,
                'message' => 'لا توجد سجلات قديمة للحذف'
            ];
        }
        
        // حذف السجلات القديمة باستخدام execute والحصول على عدد السجلات المحذوفة
        $result = $db->execute(
            "DELETE FROM login_attempts WHERE created_at < ?",
            [$cutoffDate]
        );
        
        $deletedCount = intval($result['affected_rows'] ?? 0);
        
        // التحقق من عدد السجلات بعد الحذف للتأكد
        $countAfterResult = $db->queryOne(
            "SELECT COUNT(*) as count FROM login_attempts WHERE created_at < ?",
            [$cutoffDate]
        );
        $countAfter = intval($countAfterResult['count'] ?? 0);
        
        // حساب عدد السجلات المحذوفة بناءً على الفرق
        $actualDeleted = $countBefore - $countAfter;
        
        // التحقق من أن الحذف تم فعلياً
        if ($actualDeleted > 0 || ($deletedCount > 0 && $countAfter == 0)) {
            $finalDeleted = max($actualDeleted, $deletedCount);
            error_log("Login attempts cleanup: Deleted {$finalDeleted} records (Before: {$countBefore}, After: {$countAfter}, Affected: {$deletedCount})");
            return [
                'success' => true,
                'deleted' => $finalDeleted,
                'message' => "تم حذف {$finalDeleted} سجل محاولة تسجيل دخول قديمة"
            ];
        } else if ($deletedCount > 0) {
            // إذا كان affected_rows أكبر من 0، استخدمه
            error_log("Login attempts cleanup: Deleted {$deletedCount} records using affected_rows");
            return [
                'success' => true,
                'deleted' => $deletedCount,
                'message' => "تم حذف {$deletedCount} سجل محاولة تسجيل دخول قديمة"
            ];
        } else {
            // إذا لم يتم حذف أي سجل، قد تكون هناك مشكلة
            error_log("Login attempts cleanup failed: Before={$countBefore}, After={$countAfter}, Affected={$deletedCount}");
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'فشل حذف السجلات. يرجى التحقق من قاعدة البيانات والمحاولة مرة أخرى.'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Cleanup Login Attempts Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [
            'success' => false,
            'deleted' => 0,
            'message' => 'حدث خطأ أثناء حذف السجلات القديمة: ' . $e->getMessage()
        ];
    }
}

/**
 * ضمان عدم تجاوز عدد سجلات محاولات تسجيل الدخول للحد المسموح
 */
function enforceLoginAttemptsLimit($maxCount = 50) {
    if ($maxCount < 1) {
        return true;
    }

    try {
        $db = db();

        $totalRow = $db->queryOne("SELECT COUNT(*) as count FROM login_attempts");
        $total = isset($totalRow['count']) ? (int) $totalRow['count'] : 0;

        if ($total <= $maxCount) {
            return true;
        }

        $toDelete = $total - $maxCount;
        if ($toDelete <= 0) {
            return true;
        }

        $oldAttempts = $db->query(
            "SELECT id FROM login_attempts ORDER BY created_at ASC LIMIT " . (int) $toDelete
        );

        if (empty($oldAttempts)) {
            return true;
        }

        foreach ($oldAttempts as $attempt) {
            if (!empty($attempt['id'])) {
                $db->execute("DELETE FROM login_attempts WHERE id = ?", [(int) $attempt['id']]);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Error enforcing login attempts limit: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على إحصائيات محاولات تسجيل الدخول
 */
function getLoginAttemptsStats() {
    $db = db();
    
    $total = $db->queryOne("SELECT COUNT(*) as count FROM login_attempts");
    $today = $db->queryOne(
        "SELECT COUNT(*) as count FROM login_attempts WHERE DATE(created_at) = CURDATE()"
    );
    $failed = $db->queryOne(
        "SELECT COUNT(*) as count FROM login_attempts WHERE success = 0 AND DATE(created_at) = CURDATE()"
    );
    $old = $db->queryOne(
        "SELECT COUNT(*) as count FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
    );
    
    return [
        'total' => $total['count'] ?? 0,
        'today' => $today['count'] ?? 0,
        'failed_today' => $failed['count'] ?? 0,
        'old_records' => $old['count'] ?? 0
    ];
}

