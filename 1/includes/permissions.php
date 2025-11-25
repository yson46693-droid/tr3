<?php
/**
 * نظام التصاريح المتقدم
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * التحقق من صلاحية المستخدم
 */
function hasPermission($permissionName, $userId = null) {
    if ($userId === null) {
        $currentUser = getCurrentUser();
        if (!$currentUser) {
            return false;
        }
        $userId = $currentUser['id'];
        $role = $currentUser['role'];
    } else {
        $db = db();
        $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return false;
        }
        $role = $user['role'];
    }
    
    // المدير لديه جميع الصلاحيات
    if ($role === 'manager') {
        return true;
    }
    
    $db = db();
    
    // الحصول على معرف الصلاحية
    $permission = $db->queryOne(
        "SELECT id FROM permissions WHERE name = ?",
        [$permissionName]
    );
    
    if (!$permission) {
        return false;
    }
    
    $permissionId = $permission['id'];
    
    // التحقق من صلاحيات الدور
    $rolePermission = $db->queryOne(
        "SELECT id FROM role_permissions WHERE role = ? AND permission_id = ?",
        [$role, $permissionId]
    );
    
    if ($rolePermission) {
        return true;
    }
    
    // التحقق من الصلاحيات المخصصة للمستخدم
    $userPermission = $db->queryOne(
        "SELECT granted FROM user_permissions WHERE user_id = ? AND permission_id = ?",
        [$userId, $permissionId]
    );
    
    if ($userPermission) {
        return $userPermission['granted'] == 1;
    }
    
    return false;
}

/**
 * منح صلاحية لمستخدم
 */
function grantPermission($userId, $permissionId, $grantedBy = null) {
    try {
        $db = db();
        
        // التحقق من وجود جدول user_permissions
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_permissions'");
        if (empty($tableCheck)) {
            error_log("user_permissions table does not exist");
            return false;
        }
        
        // التحقق من وجود عمود granted_by
        $grantedByColumnCheck = $db->queryOne("SHOW COLUMNS FROM user_permissions LIKE 'granted_by'");
        $hasGrantedByColumn = !empty($grantedByColumnCheck);
        
        if ($grantedBy === null && $hasGrantedByColumn) {
            $currentUser = getCurrentUser();
            $grantedBy = $currentUser['id'] ?? null;
        }
        
        // التحقق من وجود الصلاحية
        $existing = $db->queryOne(
            "SELECT id FROM user_permissions WHERE user_id = ? AND permission_id = ?",
            [$userId, $permissionId]
        );
        
        if ($existing) {
            // تحديث الصلاحية
            if ($hasGrantedByColumn && $grantedBy) {
                $db->execute(
                    "UPDATE user_permissions SET granted = 1, granted_by = ? WHERE id = ?",
                    [$grantedBy, $existing['id']]
                );
            } else {
                $db->execute(
                    "UPDATE user_permissions SET granted = 1 WHERE id = ?",
                    [$existing['id']]
                );
            }
        } else {
            // إنشاء صلاحية جديدة
            if ($hasGrantedByColumn && $grantedBy) {
                $db->execute(
                    "INSERT INTO user_permissions (user_id, permission_id, granted, granted_by) 
                     VALUES (?, ?, 1, ?)",
                    [$userId, $permissionId, $grantedBy]
                );
            } else {
                $db->execute(
                    "INSERT INTO user_permissions (user_id, permission_id, granted) 
                     VALUES (?, ?, 1)",
                    [$userId, $permissionId]
                );
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Grant Permission Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * إلغاء صلاحية من مستخدم
 */
function revokePermission($userId, $permissionId) {
    try {
        $db = db();
        
        // التحقق من وجود جدول user_permissions
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_permissions'");
        if (empty($tableCheck)) {
            error_log("user_permissions table does not exist");
            return false;
        }
        
        // التحقق من وجود السجل أولاً
        $existing = $db->queryOne(
            "SELECT id FROM user_permissions WHERE user_id = ? AND permission_id = ?",
            [$userId, $permissionId]
        );
        
        if ($existing) {
            // تحديث الصلاحية
            $db->execute(
                "UPDATE user_permissions SET granted = 0 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            // إذا لم يكن السجل موجوداً، ننشئه كـ granted = 0
            // (هذا يضمن أننا نستطيع إلغاء الصلاحية حتى لو لم تكن موجودة مسبقاً)
            $db->execute(
                "INSERT INTO user_permissions (user_id, permission_id, granted) 
                 VALUES (?, ?, 0)",
                [$userId, $permissionId]
            );
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Revoke Permission Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

/**
 * الحصول على جميع الصلاحيات
 */
function getAllPermissions() {
    try {
        $db = db();
        
        // التحقق من وجود جدول permissions
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'permissions'");
        if (empty($tableCheck)) {
            error_log("permissions table does not exist");
            return [];
        }
        
        return $db->query(
            "SELECT * FROM permissions ORDER BY category, name"
        );
    } catch (Exception $e) {
        error_log("Get All Permissions Error: " . $e->getMessage());
        return [];
    }
}

/**
 * الحصول على صلاحيات المستخدم
 */
function getUserPermissions($userId) {
    $db = db();
    
    // الصلاحيات من الدور
    $role = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
    if (!$role) {
        return [];
    }
    
    $rolePermissions = $db->query(
        "SELECT p.* FROM permissions p
         INNER JOIN role_permissions rp ON p.id = rp.permission_id
         WHERE rp.role = ?",
        [$role['role']]
    );
    
    // الصلاحيات المخصصة
    $userPermissions = $db->query(
        "SELECT p.*, up.granted FROM permissions p
         INNER JOIN user_permissions up ON p.id = up.permission_id
         WHERE up.user_id = ?",
        [$userId]
    );
    
    // دمج الصلاحيات
    $allPermissions = [];
    
    foreach ($rolePermissions as $perm) {
        $allPermissions[$perm['name']] = [
            'id' => $perm['id'],
            'name' => $perm['name'],
            'description' => $perm['description'],
            'category' => $perm['category'],
            'source' => 'role',
            'granted' => true
        ];
    }
    
    foreach ($userPermissions as $perm) {
        $allPermissions[$perm['name']] = [
            'id' => $perm['id'],
            'name' => $perm['name'],
            'description' => $perm['description'],
            'category' => $perm['category'],
            'source' => 'user',
            'granted' => $perm['granted'] == 1
        ];
    }
    
    return array_values($allPermissions);
}

/**
 * الحصول على صلاحيات الدور
 */
function getRolePermissions($role) {
    $db = db();
    
    return $db->query(
        "SELECT p.* FROM permissions p
         INNER JOIN role_permissions rp ON p.id = rp.permission_id
         WHERE rp.role = ?
         ORDER BY p.category, p.name",
        [$role]
    );
}

