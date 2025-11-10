<?php
/**
 * سجل التدقيق - تسجيل كل العمليات والتعديلات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * التأكد من أن جدول سجل التدقيق يحتوي على الأعمدة المطلوبة
 */
function ensureAuditLogSchema($db) {
    static $schemaValidated = false;

    if ($schemaValidated) {
        return;
    }

    try {
        $columnsToEnsure = [
            'old_value' => "ALTER TABLE audit_logs ADD COLUMN old_value LONGTEXT NULL AFTER entity_id",
            'new_value' => "ALTER TABLE audit_logs ADD COLUMN new_value LONGTEXT NULL AFTER old_value",
            'ip_address' => "ALTER TABLE audit_logs ADD COLUMN ip_address VARCHAR(45) NULL AFTER new_value",
            'user_agent' => "ALTER TABLE audit_logs ADD COLUMN user_agent TEXT NULL AFTER ip_address",
        ];

        foreach ($columnsToEnsure as $columnName => $alterSql) {
            $columnExists = $db->queryOne("SHOW COLUMNS FROM audit_logs LIKE ?", [$columnName]);
            if (empty($columnExists)) {
                $db->execute($alterSql);
            }
        }

        $schemaValidated = true;
    } catch (Exception $schemaError) {
        error_log('Audit schema validation error: ' . $schemaError->getMessage());
    }
}

/**
 * تسجيل عملية في سجل التدقيق
 */
function logAudit($userId, $action, $entityType, $entityId = null, $oldValue = null, $newValue = null) {
    try {
        $db = db();
        ensureAuditLogSchema($db);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // تحويل القيم إلى JSON إذا كانت مصفوفات
        if (is_array($oldValue)) {
            $oldValue = json_encode($oldValue);
        }
        if (is_array($newValue)) {
            $newValue = json_encode($newValue);
        }
        
        $sql = "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $db->execute($sql, [
            $userId,
            $action,
            $entityType,
            $entityId,
            $oldValue,
            $newValue,
            $ipAddress,
            $userAgent
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على سجل التدقيق
 */
function getAuditLogs($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT al.*, u.username, u.role 
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['user_id'])) {
        $sql .= " AND al.user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if (!empty($filters['action'])) {
        $sql .= " AND al.action = ?";
        $params[] = $filters['action'];
    }
    
    if (!empty($filters['entity_type'])) {
        $sql .= " AND al.entity_type = ?";
        $params[] = $filters['entity_type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(al.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(al.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على عدد سجلات التدقيق
 */
function getAuditLogsCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM audit_logs WHERE 1=1";
    $params = [];
    
    if (!empty($filters['user_id'])) {
        $sql .= " AND user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if (!empty($filters['action'])) {
        $sql .= " AND action = ?";
        $params[] = $filters['action'];
    }
    
    if (!empty($filters['entity_type'])) {
        $sql .= " AND entity_type = ?";
        $params[] = $filters['entity_type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

