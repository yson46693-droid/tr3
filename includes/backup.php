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
    set_time_limit(0);

    $chunkSize = 500;

    try {
        if (!defined('BASE_PATH')) {
            throw new Exception("BASE_PATH غير معرف. تحقق من ملف config.php");
        }

        $db = db();
        $connection = getDB();
        $connection->set_charset('utf8mb4');

        $backupDir = BASE_PATH . '/backups/';

        if (!file_exists($backupDir)) {
            if (!@mkdir($backupDir, 0777, true)) {
                $error = error_get_last();
                throw new Exception("فشل إنشاء مجلد النسخ الاحتياطية: " . $backupDir . " - " . ($error['message'] ?? 'سبب غير معروف'));
            }
            @chmod($backupDir, 0777);
        }

        if (!is_dir($backupDir)) {
            throw new Exception("المجلد غير موجود: " . $backupDir);
        }

        if (!is_readable($backupDir)) {
            @chmod($backupDir, 0777);
            if (!is_readable($backupDir)) {
                $perms = substr(sprintf('%o', fileperms($backupDir)), -4);
                throw new Exception("المجلد غير قابل للقراءة: " . $backupDir . " - الصلاحيات الحالية: " . $perms);
            }
        }

        if (!is_writable($backupDir)) {
            @chmod($backupDir, 0777);
            if (!is_writable($backupDir)) {
                $perms = substr(sprintf('%o', fileperms($backupDir)), -4);
                throw new Exception("المجلد غير قابل للكتابة: " . $backupDir . " - الصلاحيات الحالية: " . $perms . " - حاول تغيير صلاحيات المجلد إلى 777");
            }
        }

        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'backup_' . DB_NAME . '_' . $timestamp . '.sql';
        $filePath = $backupDir . $filename;

        $handle = @fopen($filePath, 'wb');
        if (!$handle) {
            throw new Exception("فشل فتح ملف النسخة الاحتياطية للكتابة: " . $filePath);
        }

        $write = static function (string $content) use ($handle, $filePath) {
            if (fwrite($handle, $content) === false) {
                throw new Exception("فشل كتابة البيانات إلى ملف النسخة الاحتياطية: " . $filePath);
            }
        };

        $write("-- نسخة احتياطية لقاعدة البيانات\n");
        $write("-- التاريخ: " . date('Y-m-d H:i:s') . "\n");
        $write("-- نوع النسخة: " . $backupType . "\n");
        $write("-- قاعدة البيانات: " . DB_NAME . "\n\n");
        $write("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
        $write("SET AUTOCOMMIT=0;\n");
        $write("SET time_zone='+00:00';\n");
        $write("SET NAMES utf8mb4;\n");
        $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tablesResult = $connection->query('SHOW FULL TABLES');
        if (!$tablesResult) {
            throw new Exception('تعذر الحصول على قائمة الجداول: ' . ($connection->error ?? ''));
        }

        $tableNames = [];
        $viewNames = [];
        while ($row = $tablesResult->fetch_array(MYSQLI_NUM)) {
            $name = $row[0];
            $type = strtoupper($row[1] ?? 'BASE TABLE');
            if ($type === 'VIEW') {
                $viewNames[] = $name;
            } else {
                $tableNames[] = $name;
            }
        }
        $tablesResult->free();

        if (empty($tableNames) && empty($viewNames)) {
            throw new Exception('لا توجد كائنات في قاعدة البيانات لنسخها احتياطياً');
        }

        foreach ($tableNames as $tableName) {
            $write("-- هيكل الجدول `$tableName`\n");
            $write("DROP TABLE IF EXISTS `$tableName`;\n");

            $createTableResult = $connection->query("SHOW CREATE TABLE `$tableName`");
            if (!$createTableResult || $createTableResult->num_rows === 0) {
                throw new Exception('تعذر الحصول على هيكل الجدول: ' . $tableName);
            }

            $createRow = $createTableResult->fetch_assoc();
            $write($createRow['Create Table'] . ";\n\n");
            $createTableResult->free();

            $write("-- بيانات الجدول `$tableName`\n");
            $result = $connection->query("SELECT * FROM `$tableName`");
            if (!$result) {
                throw new Exception('تعذر الحصول على بيانات الجدول: ' . $tableName . ' - ' . ($connection->error ?? ''));
            }

            if ($result->num_rows === 0) {
                $write("-- لا توجد بيانات في الجدول `$tableName`\n\n");
                $result->free();
                continue;
            }

            $fields = $result->fetch_fields();
            $columns = array_map(static function ($field) {
                return $field->name;
            }, $fields);
            $columnList = '`' . implode('`, `', $columns) . '`';

            $batch = [];
            while ($row = $result->fetch_assoc()) {
                $batch[] = formatInsertRow($connection, $columns, $row);
                if (count($batch) === $chunkSize) {
                    $write("INSERT INTO `$tableName` ($columnList) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                $write("INSERT INTO `$tableName` ($columnList) VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            $write("\n");
            $result->free();
        }

        if (!empty($viewNames)) {
            $write("-- العروض (Views)\n");
            foreach ($viewNames as $viewName) {
                $write("DROP VIEW IF EXISTS `$viewName`;\n");
                $viewResult = $connection->query("SHOW CREATE VIEW `$viewName`");
                if ($viewResult && $viewResult->num_rows > 0) {
                    $viewRow = $viewResult->fetch_assoc();
                    $write($viewRow['Create View'] . ";\n\n");
                    $viewResult->free();
                } else {
                    $write("-- تعذر استخراج العرض `$viewName`\n\n");
                }
            }
        }

        $write("COMMIT;\n");
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        $write("SET AUTOCOMMIT=1;\n");

        fclose($handle);

        $compressedPath = null;
        try {
            $compressedPath = compressBackup($filePath);
            if ($compressedPath && file_exists($compressedPath)) {
                $compressedSize = filesize($compressedPath);
                if ($compressedSize > 0) {
                    @unlink($filePath);
                    $filePath = $compressedPath;
                    $filename = basename($compressedPath);
                } else {
                    @unlink($compressedPath);
                    $compressedPath = null;
                }
            }
        } catch (Exception $compressionError) {
            error_log('Compression failed: ' . $compressionError->getMessage());
            if ($compressedPath && file_exists($compressedPath)) {
                @unlink($compressedPath);
            }
        }

        if (!file_exists($filePath)) {
            throw new Exception('ملف النسخة الاحتياطية غير موجود بعد الحفظ: ' . $filePath);
        }

        $finalFileSize = filesize($filePath);
        if ($finalFileSize === false || $finalFileSize === 0) {
            throw new Exception('حجم ملف النسخة الاحتياطية غير صحيح');
        }

        $db->execute(
            "INSERT INTO backups (filename, file_path, file_size, backup_type, status, created_by) 
             VALUES (?, ?, ?, ?, 'completed', ?)",
            [$filename, $filePath, $finalFileSize, $backupType, $userId]
        );

        $maxBackupsToKeep = 9;
        deleteOldBackups($backupType, $maxBackupsToKeep);
        enforceBackupLimit($maxBackupsToKeep);

        return [
            'success' => true,
            'filename' => $filename,
            'file_path' => $filePath,
            'file_size' => $finalFileSize,
            'message' => 'تم إنشاء النسخة الاحتياطية بنجاح'
        ];
    } catch (Exception $e) {
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }

        if (isset($filePath) && file_exists($filePath)) {
            @unlink($filePath);
        }

        if (isset($db)) {
            try {
                $db->execute(
                    "INSERT INTO backups (filename, file_path, backup_type, status, error_message, created_by) 
                     VALUES (?, ?, ?, 'failed', ?, ?)",
                    ['', '', $backupType, $e->getMessage(), $userId]
                );
            } catch (Exception $dbError) {
                error_log('Failed to log backup error: ' . $dbError->getMessage());
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
 * ضمان عدم تجاوز عدد النسخ الاحتياطية للحد المسموح
 */
function enforceBackupLimit($maxCount = 9) {
    if ($maxCount < 1) {
        return true;
    }

    try {
        $db = db();

        $totalRow = $db->queryOne(
            "SELECT COUNT(*) AS total FROM backups WHERE status IN ('completed', 'success')"
        );

        $total = isset($totalRow['total']) ? (int) $totalRow['total'] : 0;

        if ($total < $maxCount + 1) {
            return true;
        }

        $toDelete = $total - $maxCount;
        if ($toDelete <= 0) {
            return true;
        }

        $oldBackups = $db->query(
            "SELECT id, file_path FROM backups WHERE status IN ('completed', 'success') ORDER BY created_at ASC LIMIT " . (int) $toDelete
        );

        foreach ($oldBackups as $backup) {
            if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }

            if (!empty($backup['id'])) {
                $db->execute("DELETE FROM backups WHERE id = ?", [(int) $backup['id']]);
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Error enforcing backup limit: " . $e->getMessage());
        return false;
    }
}

/**
 * استعادة قاعدة البيانات من نسخة احتياطية
 */
function restoreDatabase($backupId) {
    set_time_limit(0);

    try {
        $db = db();

        $backup = $db->queryOne(
            "SELECT * FROM backups WHERE id = ? AND status IN ('completed', 'success')",
            [$backupId]
        );

        if (!$backup) {
            throw new Exception('النسخة الاحتياطية غير موجودة');
        }

        if (!file_exists($backup['file_path'])) {
            throw new Exception('ملف النسخة الاحتياطية غير موجود');
        }

        $rawContent = file_get_contents($backup['file_path']);
        if ($rawContent === false) {
            throw new Exception('تعذر قراءة ملف النسخة الاحتياطية');
        }

        if (pathinfo($backup['file_path'], PATHINFO_EXTENSION) === 'gz') {
            $decoded = gzdecode($rawContent);
            if ($decoded === false) {
                throw new Exception('تعذر فك ضغط ملف النسخة الاحتياطية');
            }
            $rawContent = $decoded;
        }

        if (trim($rawContent) === '') {
            throw new Exception('ملف النسخة الاحتياطية فارغ');
        }

        $statements = splitSqlStatements($rawContent);
        if (empty($statements)) {
            throw new Exception('تعذر تحليل استعلامات النسخة الاحتياطية');
        }

        $connection = getDB();
        $connection->set_charset('utf8mb4');

        $connection->autocommit(false);
        $connection->query('SET FOREIGN_KEY_CHECKS = 0');
        $connection->query('SET UNIQUE_CHECKS = 0');

        try {
            foreach ($statements as $statement) {
                $normalized = strtoupper(ltrim($statement));
                if ($normalized === '' || $normalized === ';') {
                    continue;
                }

                if (
                    strpos($normalized, 'START TRANSACTION') === 0 ||
                    strpos($normalized, 'COMMIT') === 0 ||
                    strpos($normalized, 'ROLLBACK') === 0 ||
                    strpos($normalized, 'SET AUTOCOMMIT') === 0 ||
                    strpos($normalized, 'LOCK TABLES') === 0 ||
                    strpos($normalized, 'UNLOCK TABLES') === 0
                ) {
                    continue;
                }

                if ($connection->query($statement) === false) {
                    $error = $connection->error ?: 'خطأ غير معروف أثناء تنفيذ الاستعلام';
                    throw new Exception($error);
                }
            }

            $connection->commit();
        } catch (Exception $executionError) {
            $connection->rollback();
            throw $executionError;
        } finally {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
            $connection->query('SET UNIQUE_CHECKS = 1');
            $connection->autocommit(true);
        }

        return [
            'success' => true,
            'message' => 'تم استعادة قاعدة البيانات بنجاح'
        ];
    } catch (Exception $e) {
        if (isset($connection) && $connection instanceof mysqli) {
            $connection->query('SET FOREIGN_KEY_CHECKS = 1');
            $connection->query('SET UNIQUE_CHECKS = 1');
            $connection->autocommit(true);
        }

        return [
            'success' => false,
            'message' => 'فشل استعادة النسخة الاحتياطية: ' . $e->getMessage()
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

function formatInsertRow(\mysqli $connection, array $columns, array $row): string {
    $values = [];

    foreach ($columns as $column) {
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            $values[] = 'NULL';
            continue;
        }

        $value = $row[$column];

        if (is_bool($value)) {
            $values[] = $value ? '1' : '0';
            continue;
        }

        $values[] = "'" . $connection->real_escape_string((string) $value) . "'";
    }

    return '(' . implode(',', $values) . ')';
}

function splitSqlStatements(string $sqlContent): array {
    $statements = [];
    $length = strlen($sqlContent);
    $buffer = '';

    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sqlContent[$i];
        $nextChar = $i + 1 < $length ? $sqlContent[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $nextChar === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $nextChar === '-' && ($i + 2 < $length && ($sqlContent[$i + 2] === ' ' || $sqlContent[$i + 2] === "\t"))) {
                $inLineComment = true;
                $i++;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $nextChar === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === '\\' && ($inSingle || $inDouble)) {
            $buffer .= $char;
            if ($i + 1 < $length) {
                $buffer .= $sqlContent[++$i];
            }
            continue;
        }

        if ($char === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement . ';';
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

