<?php
/**
 * API النسخ الاحتياطي
 */

define('ACCESS_ALLOWED', true);

// تعطيل تشغيل النسخ الاحتياطي التلقائي أثناء استدعاءات الـ API لتجنب تشغيله عند الحذف
if (!defined('ENABLE_DAILY_BACKUP_DELIVERY')) {
    define('ENABLE_DAILY_BACKUP_DELIVERY', false);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/daily_backup_sender.php';

header('Content-Type: application/json');
requireRole('manager');

$currentUser = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_backup') {
        $backupType = $_POST['backup_type'] ?? 'manual';
        
        try {
            $result = createDatabaseBackup($backupType, $currentUser['id']);
            
            if ($result['success']) {
                logAudit($currentUser['id'], 'create_backup', 'backup', null, null, [
                    'type' => $backupType,
                    'filename' => $result['filename'] ?? ''
                ]);
            }
            
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()
            ]);
        }
        
    } elseif ($action === 'restore') {
        $backupId = intval($_POST['backup_id'] ?? 0);
        
        if ($backupId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid backup ID']);
            exit;
        }
        
        try {
            $result = restoreDatabase($backupId);
            
            if ($result['success']) {
                logAudit($currentUser['id'], 'restore_backup', 'backup', $backupId, null, null);
            }
            
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'فشل استعادة النسخة الاحتياطية: ' . $e->getMessage()
            ]);
        }
        
    } elseif ($action === 'delete') {
        $backupId = intval($_POST['backup_id'] ?? 0);
        
        if ($backupId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid backup ID']);
            exit;
        }
        
        try {
            $db = db();
            $backup = $db->queryOne(
                "SELECT id, filename, file_path, backup_type, status, created_by, created_at 
                 FROM backups WHERE id = ?",
                [$backupId]
            );
            
            if (!$backup) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'النسخة الاحتياطية غير موجودة']);
                exit;
            }
            
            // حذف الملف إذا كان موجوداً
            if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                if (!@unlink($backup['file_path'])) {
                    error_log("Failed to delete backup file: " . $backup['file_path']);
                }
            }
            
            // حذف من قاعدة البيانات
            $db->execute("DELETE FROM backups WHERE id = ?", [$backupId]);
            
            // تسجيل عملية الحذف في سجل التدقيق
            logAudit($currentUser['id'], 'delete_backup', 'backup', $backupId, null, null);

            // في حال تم حذف نسخة احتياطية أنشأها النظام، لا نريد إعادة إرسالها تلقائياً
            if (
                function_exists('dailyBackupRegisterManualDeletion') &&
                ($backup['backup_type'] ?? '') === 'daily' &&
                empty($backup['created_by'])
            ) {
                dailyBackupRegisterManualDeletion($backup, $currentUser['id'] ?? null);
            }
            
            echo json_encode(['success' => true, 'message' => 'تم حذف النسخة الاحتياطية بنجاح']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'فشل حذف النسخة الاحتياطية: ' . $e->getMessage()]);
        }
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        $limit = intval($_GET['limit'] ?? 50);
        $backupType = $_GET['type'] ?? null;
        
        $backups = getBackups($limit, $backupType);
        
        echo json_encode([
            'success' => true,
            'data' => $backups
        ]);
        
    } elseif ($action === 'stats') {
        $stats = getBackupStats();
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}
