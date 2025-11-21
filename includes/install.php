<?php
/**
 * نظام التهيئة التلقائي لقاعدة البيانات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * تهيئة قاعدة البيانات
 */
function initializeDatabase() {
    try {
        // الاتصال بقاعدة البيانات بدون تحديد قاعدة معينة أولاً
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
        
        if ($connection->connect_error) {
            throw new Exception("Connection failed: " . $connection->connect_error);
        }
        
        // إنشاء قاعدة البيانات إذا لم تكن موجودة
        $connection->set_charset("utf8mb4");
        $connection->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection->select_db(DB_NAME);
        
        // قراءة ملف SQL
        $sqlFile = __DIR__ . '/../database/schema.sql';
        
        if (!file_exists($sqlFile)) {
            throw new Exception("SQL file not found");
        }
        
        $sql = file_get_contents($sqlFile);
        
        // إزالة تعليقات CREATE DATABASE و USE
        $sql = preg_replace('/CREATE DATABASE.*?;/i', '', $sql);
        $sql = preg_replace('/USE.*?;/i', '', $sql);
        $sql = preg_replace('/SET SQL_MODE.*?;/i', '', $sql);
        $sql = preg_replace('/SET time_zone.*?;/i', '', $sql);
        
        // تقسيم الاستعلامات
        $queries = array_filter(array_map('trim', explode(';', $sql)));
        
        // تنفيذ كل استعلام
        foreach ($queries as $query) {
            if (!empty($query) && !preg_match('/^--/', $query)) {
                $connection->query($query);
            }
        }
        
        $connection->close();
        
        return ['success' => true, 'message' => 'تم تهيئة قاعدة البيانات بنجاح'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'خطأ في تهيئة قاعدة البيانات: ' . $e->getMessage()];
    }
}

/**
 * التحقق من وجود قاعدة البيانات
 */
function checkDatabaseExists() {
    try {
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
        
        if ($connection->connect_error) {
            return false;
        }
        
        $result = $connection->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . DB_NAME . "'");
        $exists = $result && $result->num_rows > 0;
        
        $connection->close();
        
        return $exists;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * التحقق من وجود الجداول
 */
function checkTablesExist() {
    try {
        // محاولة الاتصال بقاعدة البيانات
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($connection->connect_error) {
            return false;
        }
        
        $tables = [
            'users', 'webauthn_credentials', 'attendance', 'suppliers',
            'financial_transactions', 'customers', 'products', 'sales',
            'collections', 'salaries', 'production', 'production_materials',
            'approvals', 'audit_logs', 'notifications', 'reports'
        ];
        
        foreach ($tables as $table) {
            $result = $connection->query("SHOW TABLES LIKE '$table'");
            if (!$result || $result->num_rows === 0) {
                $connection->close();
                return false;
            }
        }
        
        $connection->close();
        
        // استيراد بيانات التغليف إذا كانت موجودة
        $packagingResult = importPackagingData();
        if ($packagingResult['success']) {
            error_log("Packaging data imported: " . $packagingResult['message']);
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * التحقق من البيانات الأولية
 */
function checkInitialData() {
    try {
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($connection->connect_error) {
            return false;
        }
        
        $result = $connection->query("SELECT COUNT(*) as count FROM users");
        if ($result) {
            $row = $result->fetch_assoc();
            $connection->close();
            return ($row && $row['count'] > 0);
        }
        
        $connection->close();
        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * استيراد بيانات التغليف من JSON
 */
function importPackagingData() {
    try {
        $jsonFile = __DIR__ . '/../packaging.json';
        
        if (!file_exists($jsonFile)) {
            return ['success' => false, 'message' => 'ملف packaging.json غير موجود'];
        }
        
        $jsonData = file_get_contents($jsonFile);
        $packaging = json_decode($jsonData, true);
        
        if (!$packaging || !is_array($packaging)) {
            return ['success' => false, 'message' => 'خطأ في قراءة ملف JSON'];
        }
        
        // استخدام اتصال مباشر بدلاً من db() لأنها قد لا تكون متاحة بعد
        $connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        
        if ($connection->connect_error) {
            return ['success' => false, 'message' => 'خطأ في الاتصال بقاعدة البيانات: ' . $connection->connect_error];
        }
        
        $connection->set_charset("utf8mb4");
        
        $imported = 0;
        $updated = 0;
        
        foreach ($packaging as $item) {
            // التحقق من وجود المنتج
            $stmt = $connection->prepare("SELECT id FROM products WHERE name = ? AND category = ?");
            $stmt->bind_param("ss", $item['specifications'], $item['type']);
            $stmt->execute();
            $result = $stmt->get_result();
            $existing = $result->fetch_assoc();
            $stmt->close();
            
            if ($existing) {
                // تحديث المنتج الموجود
                $stmt = $connection->prepare("UPDATE products SET quantity = ?, unit = ?, updated_at = NOW() WHERE id = ?");
                $stmt->bind_param("dsi", $item['quantity'], $item['unit'], $existing['id']);
                $stmt->execute();
                $stmt->close();
                $updated++;
            } else {
                // إضافة منتج جديد
                $description = "ID: {$item['id']}";
                $stmt = $connection->prepare("INSERT INTO products (name, category, quantity, unit, description, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $stmt->bind_param("ssdss", $item['specifications'], $item['type'], $item['quantity'], $item['unit'], $description);
                $stmt->execute();
                $stmt->close();
                $imported++;
            }
        }
        
        $connection->close();
        
        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'message' => "تم استيراد $imported منتج وتحديث $updated منتج"
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'خطأ في الاستيراد: ' . $e->getMessage()];
    }
}

/**
 * التحقق من الحاجة للتهيئة
 */
function needsInstallation() {
    // التحقق من وجود قاعدة البيانات
    if (!checkDatabaseExists()) {
        return true;
    }
    
    // التحقق من وجود الجداول
    if (!checkTablesExist()) {
        return true;
    }
    
    // التحقق من البيانات الأولية
    if (!checkInitialData()) {
        return true;
    }
    
    return false;
}

