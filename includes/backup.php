<?php
/**
 * نظام النسخ الاحتياطي لقاعدة البيانات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * إنشاء نسخة احتياطية لقاعدة البيانات
 */
function createDatabaseBackup($backupType = 'daily', $userId = null) {
    try {
        $db = db();
        
        // إنشاء مجلد النسخ الاحتياطية
        $backupDir = BASE_PATH . '/backups/';
        
        // التأكد من أن BASE_PATH معرف
        if (!defined('BASE_PATH')) {
            throw new Exception("BASE_PATH غير معرف. تحقق من ملف config.php");
        }
        
        // إنشاء المجلد إذا لم يكن موجوداً
        if (!file_exists($backupDir)) {
            if (!@mkdir($backupDir, 0777, true)) {
                $error = error_get_last();
                throw new Exception("فشل إنشاء مجلد النسخ الاحتياطية: " . $backupDir . " - " . ($error['message'] ?? 'سبب غير معروف'));
            }
            // محاولة تغيير الصلاحيات بعد الإنشاء
            @chmod($backupDir, 0777);
        }
        
        // التأكد من أن المجلد موجود
        if (!is_dir($backupDir)) {
            throw new Exception("المجلد غير موجود: " . $backupDir);
        }
        
        // التأكد من أن المجلد قابل للقراءة
        if (!is_readable($backupDir)) {
            // محاولة تغيير الصلاحيات
            @chmod($backupDir, 0777);
            if (!is_readable($backupDir)) {
                $perms = substr(sprintf('%o', fileperms($backupDir)), -4);
                throw new Exception("المجلد غير قابل للقراءة: " . $backupDir . " - الصلاحيات الحالية: " . $perms);
            }
        }
        
        // اسم الملف
        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'backup_' . DB_NAME . '_' . $timestamp . '.sql';
        $filePath = $backupDir . $filename;
        
        // الحصول على اتصال mysqli مباشر
        $connection = getDB();
        
        // الحصول على جميع الجداول
        $tablesResult = $connection->query("SHOW TABLES");
        
        if (!$tablesResult || $tablesResult->num_rows === 0) {
            throw new Exception("لا توجد جداول في قاعدة البيانات");
        }
        
        $sqlContent = "-- نسخة احتياطية لقاعدة البيانات\n";
        $sqlContent .= "-- التاريخ: " . date('Y-m-d H:i:s') . "\n";
        $sqlContent .= "-- نوع النسخة: $backupType\n";
        $sqlContent .= "-- قاعدة البيانات: " . DB_NAME . "\n\n";
        $sqlContent .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sqlContent .= "SET time_zone = \"+02:00\";\n\n";
        
        // الحصول على كل جدول
        $tableNames = [];
        while ($row = $tablesResult->fetch_array()) {
            $tableNames[] = $row[0];
        }
        
        foreach ($tableNames as $tableName) {
            // هيكل الجدول
            $sqlContent .= "-- هيكل الجدول `$tableName`\n";
            $sqlContent .= "DROP TABLE IF EXISTS `$tableName`;\n";
            
            $createTableResult = $connection->query("SHOW CREATE TABLE `$tableName`");
            if ($createTableResult && $createTableResult->num_rows > 0) {
                $row = $createTableResult->fetch_assoc();
                $sqlContent .= $row['Create Table'] . ";\n\n";
                $createTableResult->free();
            }
            
            // بيانات الجدول
            $sqlContent .= "-- بيانات الجدول `$tableName`\n";
            $result = $connection->query("SELECT * FROM `$tableName`");
            
            if ($result && $result->num_rows > 0) {
                // الحصول على أسماء الأعمدة
                $columns = [];
                $fieldResult = $connection->query("SHOW COLUMNS FROM `$tableName`");
                if ($fieldResult) {
                    while ($field = $fieldResult->fetch_assoc()) {
                        $columns[] = $field['Field'];
                    }
                    $fieldResult->free();
                }
                
                if (!empty($columns)) {
                    $sqlContent .= "INSERT INTO `$tableName` (`" . implode("`, `", $columns) . "`) VALUES\n";
                    $values = [];
                    
                    while ($row = $result->fetch_assoc()) {
                        $rowValues = [];
                        foreach ($columns as $column) {
                            $value = $row[$column] ?? null;
                            if ($value === null) {
                                $rowValues[] = 'NULL';
                            } else {
                                $rowValues[] = "'" . $connection->real_escape_string($value) . "'";
                            }
                        }
                        $values[] = "(" . implode(",", $rowValues) . ")";
                    }
                    
                    $sqlContent .= implode(",\n", $values) . ";\n\n";
                }
                $result->free();
            }
        }
        
        // التأكد من أن المجلد قابل للكتابة
        if (!is_writable($backupDir)) {
            // محاولة تغيير الصلاحيات
            @chmod($backupDir, 0777);
            if (!is_writable($backupDir)) {
                $perms = substr(sprintf('%o', fileperms($backupDir)), -4);
                throw new Exception("المجلد غير قابل للكتابة: " . $backupDir . " - الصلاحيات الحالية: " . $perms . " - حاول تغيير صلاحيات المجلد إلى 777");
            }
        }
        
        // حفظ الملف
        $fileSize = @file_put_contents($filePath, $sqlContent, LOCK_EX);
        
        if ($fileSize === false) {
            $error = error_get_last();
            $errorMsg = $error['message'] ?? 'سبب غير معروف';
            throw new Exception("فشل حفظ ملف النسخة الاحتياطية. تحقق من صلاحيات المجلد: " . $backupDir . " - الخطأ: " . $errorMsg);
        }
        
        // التأكد من أن الملف تم حفظه بشكل صحيح
        if (!file_exists($filePath)) {
            throw new Exception("الملف غير موجود بعد الحفظ: " . $filePath);
        }
        
        // ضغط الملف (اختياري) - تخطي الضغط إذا كان هناك مشكلة
        $compressedPath = null;
        try {
            $compressedPath = compressBackup($filePath);
            if ($compressedPath && file_exists($compressedPath)) {
                $compressedSize = filesize($compressedPath);
                if ($compressedSize > 0) {
                    @unlink($filePath); // حذف الملف غير المضغوط
                    $filePath = $compressedPath;
                    $filename = basename($compressedPath);
                    $fileSize = $compressedSize;
                } else {
                    // إذا كان الملف المضغوط فارغاً، استخدم الملف الأصلي
                    @unlink($compressedPath);
                    $compressedPath = null;
                }
            }
        } catch (Exception $compressionError) {
            // تجاهل خطأ الضغط واستخدم الملف الأصلي
            error_log("Compression failed: " . $compressionError->getMessage());
            if ($compressedPath && file_exists($compressedPath)) {
                @unlink($compressedPath);
            }
        }
        
        // التأكد من أن الملف موجود قبل الحفظ
        if (!file_exists($filePath)) {
            throw new Exception("ملف النسخة الاحتياطية غير موجود بعد الحفظ: " . $filePath);
        }
        
        $finalFileSize = filesize($filePath);
        if ($finalFileSize === false || $finalFileSize === 0) {
            throw new Exception("حجم ملف النسخة الاحتياطية غير صحيح: " . $finalFileSize);
        }
        
        // حفظ في قاعدة البيانات
        try {
            $db->execute(
                "INSERT INTO backups (filename, file_path, file_size, backup_type, status, created_by) 
                 VALUES (?, ?, ?, ?, 'completed', ?)",
                [$filename, $filePath, $finalFileSize, $backupType, $userId]
            );
        } catch (Exception $dbError) {
            // إذا فشل حفظ السجل، احذف الملف
            @unlink($filePath);
            throw new Exception("فشل حفظ سجل النسخة الاحتياطية: " . $dbError->getMessage());
        }
        
        // حذف النسخ القديمة (احتفظ بآخر 30 نسخة يومية)
        if ($backupType === 'daily') {
            deleteOldBackups('daily', 30);
        }
        
        return [
            'success' => true,
            'filename' => $filename,
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'message' => 'تم إنشاء النسخة الاحتياطية بنجاح'
        ];
        
    } catch (Exception $e) {
        // تسجيل الخطأ
        if (isset($db)) {
            try {
                $db->execute(
                    "INSERT INTO backups (filename, file_path, backup_type, status, error_message, created_by) 
                     VALUES (?, ?, ?, 'failed', ?, ?)",
                    ['', '', $backupType, $e->getMessage(), $userId]
                );
            } catch (Exception $dbError) {
                error_log("Failed to log backup error: " . $dbError->getMessage());
            }
        }
        
        return [
            'success' => false,
            'message' => 'فشل إنشاء النسخة الاحتياطية: ' . $e->getMessage()
        ];
    }
}

/**
 * ضغط ملف النسخة الاحتياطية
 */
function compressBackup($filePath) {
    if (!function_exists('gzencode')) {
        return false;
    }
    
    $compressedPath = $filePath . '.gz';
    $content = file_get_contents($filePath);
    $compressed = gzencode($content, 9);
    
    if (file_put_contents($compressedPath, $compressed)) {
        return $compressedPath;
    }
    
    return false;
}

/**
 * حذف النسخ الاحتياطية القديمة
 */
function deleteOldBackups($backupType = 'daily', $keepCount = 30) {
    try {
        $db = db();
        
        // الحصول على النسخ القديمة
        $oldBackups = $db->query(
            "SELECT id, file_path FROM backups 
             WHERE backup_type = ? AND status IN ('completed', 'success')
             ORDER BY created_at DESC
             LIMIT 1000 OFFSET ?",
            [$backupType, $keepCount]
        );
        
        foreach ($oldBackups as $backup) {
            // حذف الملف
            if (file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }
            
            // حذف من قاعدة البيانات
            $db->execute("DELETE FROM backups WHERE id = ?", [$backup['id']]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error deleting old backups: " . $e->getMessage());
        return false;
    }
}

/**
 * استعادة قاعدة البيانات من نسخة احتياطية
 */
function restoreDatabase($backupId) {
    try {
        $db = db();
        
        // الحصول على معلومات النسخة
        $backup = $db->queryOne(
            "SELECT * FROM backups WHERE id = ? AND status IN ('completed', 'success')",
            [$backupId]
        );
        
        if (!$backup) {
            throw new Exception("النسخة الاحتياطية غير موجودة");
        }
        
        if (!file_exists($backup['file_path'])) {
            throw new Exception("ملف النسخة الاحتياطية غير موجود");
        }
        
        // قراءة ملف النسخة
        $sqlContent = file_get_contents($backup['file_path']);
        
        // إذا كان ملف مضغوط
        if (pathinfo($backup['file_path'], PATHINFO_EXTENSION) === 'gz') {
            $sqlContent = gzdecode($sqlContent);
        }
        
        // إزالة تعليقات CREATE DATABASE و USE
        $sqlContent = preg_replace('/CREATE DATABASE.*?;/i', '', $sqlContent);
        $sqlContent = preg_replace('/USE.*?;/i', '', $sqlContent);
        
        // تقسيم الاستعلامات
        $queries = array_filter(array_map('trim', explode(';', $sqlContent)));
        
        $connection = getDB();
        
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');
        
        foreach ($queries as $query) {
            if (empty($query) || preg_match('/^--/', $query)) {
                continue;
            }
            $result = $connection->query($query);
            if ($result === false) {
                $error = $connection->error ?? '';
                $lowerError = strtolower($error);
                
                if (strpos($lowerError, 'already exists') !== false && preg_match('/CREATE\s+TABLE\s+`?([a-z0-9_]+)`?/i', $query, $matches)) {
                    $tableName = $matches[1];
                    $connection->query("DROP TABLE IF EXISTS `$tableName`");
                    if (!$connection->query($query)) {
                        continue;
                    }
                    continue;
                }
                
                if (strpos($lowerError, 'duplicate column') !== false ||
                    strpos($lowerError, 'unknown table') !== false ||
                    strpos($lowerError, 'duplicate entry') !== false ||
                    (strpos($lowerError, 'unknown column') !== false && strpos($query, 'DROP') !== false)) {
                    continue;
                }
                
                throw new Exception($error ?: 'خطأ غير معروف أثناء تنفيذ الاستعلام');
            }
        }
        
        $connection->query('SET FOREIGN_KEY_CHECKS = 1');
        
        return [
            'success' => true,
            'message' => 'تم استعادة قاعدة البيانات بنجاح'
        ];
        
    } catch (Exception $e) {
        if (isset($connection)) {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        return [
            'success' => false,
            'message' => 'فشل استعادة قاعدة البيانات: ' . $e->getMessage()
        ];
    }
}

/**
 * الحصول على قائمة النسخ الاحتياطية
 */
function getBackups($limit = 50, $backupType = null) {
    $db = db();
    
    $sql = "SELECT b.*, u.username as created_by_name 
            FROM backups b
            LEFT JOIN users u ON b.created_by = u.id";
    
    $params = [];
    
    if ($backupType) {
        $sql .= " WHERE b.backup_type = ?";
        $params[] = $backupType;
    }
    
    $sql .= " ORDER BY b.created_at DESC LIMIT ?";
    $params[] = $limit;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على إحصائيات النسخ الاحتياطية
 */
function getBackupStats() {
    $db = db();
    
    $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'total_size' => 0,
        'daily' => 0,
        'weekly' => 0,
        'monthly' => 0,
        'manual' => 0
    ];
    
    $result = $db->query(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('completed', 'success') THEN 1 ELSE 0 END) as success,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
            SUM(file_size) as total_size,
            SUM(CASE WHEN backup_type = 'daily' THEN 1 ELSE 0 END) as daily,
            SUM(CASE WHEN backup_type = 'weekly' THEN 1 ELSE 0 END) as weekly,
            SUM(CASE WHEN backup_type = 'monthly' THEN 1 ELSE 0 END) as monthly,
            SUM(CASE WHEN backup_type = 'manual' THEN 1 ELSE 0 END) as manual
         FROM backups"
    );
    
    if (!empty($result)) {
        $stats = array_merge($stats, $result[0]);
    }
    
    return $stats;
}

/**
 * تنسيق حجم الملف
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' B';
    }
}

