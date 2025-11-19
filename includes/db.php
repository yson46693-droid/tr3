<?php
/**
 * اتصال قاعدة البيانات MySQL
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $connection;
    private $inTransaction = false;
    
    private function __construct() {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            // تعيين ترميز UTF-8
            $this->connection->set_charset("utf8mb4");
            
            // تعيين المنطقة الزمنية لتوقيت القاهرة (UTC+2)
            // مصر تستخدم توقيت UTC+2 بدون توقيت صيفي
            $this->connection->query("SET time_zone = '+02:00'");
            
            try {
                $columnCheck = $this->connection->query("SHOW COLUMNS FROM `users` LIKE 'profile_photo'");
                if ($columnCheck instanceof mysqli_result) {
                    if ($columnCheck->num_rows === 0) {
                        $this->connection->query("ALTER TABLE `users` ADD COLUMN `profile_photo` LONGTEXT NULL AFTER `phone`");
                    }
                    $columnCheck->free();
                }
            } catch (Throwable $migrationError) {
                error_log('Profile photo column migration error: ' . $migrationError->getMessage());
            }

            $this->ensureVehicleInventoryAutoUpgrade();
            
        } catch (Exception $e) {
            die("Database connection error: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // منع الاستنساخ
    private function __clone() {}
    
    // منع إلغاء التسلسل
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * تنفيذ استعلام SELECT
     */
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt->close();
        return $data;
    }
    
    /**
     * تنفيذ استعلام SELECT واحد
     */
    public function queryOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        return !empty($result) ? $result[0] : null;
    }
    
    /**
     * تنفيذ استعلام INSERT/UPDATE/DELETE
     */
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $insertId = $stmt->insert_id;
        $stmt->close();
        
        return [
            'affected_rows' => $affectedRows,
            'insert_id' => $insertId
        ];
    }
    
    /**
     * بدء معاملة
     */
    public function beginTransaction() {
        $this->inTransaction = true;
        return $this->connection->begin_transaction();
    }
    
    /**
     * تأكيد المعاملة
     */
    public function commit() {
        $this->inTransaction = false;
        return $this->connection->commit();
    }
    
    /**
     * إلغاء المعاملة
     */
    public function rollback() {
        $this->inTransaction = false;
        return $this->connection->rollback();
    }
    
    /**
     * التحقق من وجود معاملة نشطة
     */
    public function inTransaction() {
        return $this->inTransaction;
    }
    
    /**
     * الهروب من الأحرف الخاصة
     */
    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }
    
    /**
     * الحصول على آخر معرف تم إدراجه
     */
    public function getLastInsertId() {
        return $this->connection->insert_id;
    }
    
    /**
     * إغلاق الاتصال
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    /**
     * تشغيل ترقية مخزن سيارات المندوبين تلقائياً مرة واحدة
     */
    private function ensureVehicleInventoryAutoUpgrade(): void
    {
        static $upgradeEnsured = false;

        if ($upgradeEnsured) {
            return;
        }

        $upgradeEnsured = true;

        try {
            $flagFile = dirname(__DIR__) . '/runtime/vehicle_inventory_upgrade.flag';

            if (file_exists($flagFile)) {
                return;
            }

            $tableExists = $this->connection->query("SHOW TABLES LIKE 'vehicle_inventory'");
            if (!$tableExists instanceof mysqli_result || $tableExists->num_rows === 0) {
                return;
            }
            $tableExists->free();

            $columnsResult = $this->connection->query("SHOW COLUMNS FROM vehicle_inventory");
            $existingColumns = [];
            if ($columnsResult instanceof mysqli_result) {
                while ($column = $columnsResult->fetch_assoc()) {
                    if (!empty($column['Field'])) {
                        $existingColumns[strtolower($column['Field'])] = true;
                    }
                }
                $columnsResult->free();
            }

            $alterParts = [];

            if (!isset($existingColumns['warehouse_id'])) {
                $alterParts[] = "ADD COLUMN `warehouse_id` int(11) DEFAULT NULL COMMENT 'مخزن السيارة' AFTER `vehicle_id`";
            }
            if (!isset($existingColumns['product_name'])) {
                $alterParts[] = "ADD COLUMN `product_name` varchar(255) DEFAULT NULL AFTER `product_id`";
            }
            if (!isset($existingColumns['product_category'])) {
                $alterParts[] = "ADD COLUMN `product_category` varchar(100) DEFAULT NULL AFTER `product_name`";
            }
            if (!isset($existingColumns['product_unit'])) {
                $alterParts[] = "ADD COLUMN `product_unit` varchar(50) DEFAULT NULL AFTER `product_category`";
            }
            if (!isset($existingColumns['product_unit_price'])) {
                $alterParts[] = "ADD COLUMN `product_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit`";
            }
            if (!isset($existingColumns['product_snapshot'])) {
                $alterParts[] = "ADD COLUMN `product_snapshot` longtext DEFAULT NULL AFTER `product_unit_price`";
            }
            if (!isset($existingColumns['manager_unit_price'])) {
                $alterParts[] = "ADD COLUMN `manager_unit_price` decimal(15,2) DEFAULT NULL AFTER `product_unit_price`";
            }
            if (!isset($existingColumns['finished_batch_id'])) {
                $alterParts[] = "ADD COLUMN `finished_batch_id` int(11) DEFAULT NULL AFTER `manager_unit_price`";
            }
            if (!isset($existingColumns['finished_batch_number'])) {
                $alterParts[] = "ADD COLUMN `finished_batch_number` varchar(100) DEFAULT NULL AFTER `finished_batch_id`";
            }
            if (!isset($existingColumns['finished_production_date'])) {
                $alterParts[] = "ADD COLUMN `finished_production_date` date DEFAULT NULL AFTER `finished_batch_number`";
            }
            if (!isset($existingColumns['finished_quantity_produced'])) {
                $alterParts[] = "ADD COLUMN `finished_quantity_produced` decimal(12,2) DEFAULT NULL AFTER `finished_production_date`";
            }
            if (!isset($existingColumns['finished_workers'])) {
                $alterParts[] = "ADD COLUMN `finished_workers` text DEFAULT NULL AFTER `finished_quantity_produced`";
            }
            if (!isset($existingColumns['quantity'])) {
                $alterParts[] = "ADD COLUMN `quantity` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `finished_workers`";
            }
            if (!isset($existingColumns['last_updated_by'])) {
                $alterParts[] = "ADD COLUMN `last_updated_by` int(11) DEFAULT NULL AFTER `quantity`";
            }
            if (!isset($existingColumns['last_updated_at'])) {
                $alterParts[] = "ADD COLUMN `last_updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `last_updated_by`";
            }
            if (!isset($existingColumns['created_at'])) {
                $alterParts[] = "ADD COLUMN `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_updated_at`";
            }

            if (!empty($alterParts)) {
                $this->connection->query("ALTER TABLE vehicle_inventory " . implode(', ', $alterParts));
            }

            $indexesResult = $this->connection->query("SHOW INDEXES FROM vehicle_inventory");
            $existingIndexes = [];
            if ($indexesResult instanceof mysqli_result) {
                while ($index = $indexesResult->fetch_assoc()) {
                    if (!empty($index['Key_name'])) {
                        $existingIndexes[strtolower($index['Key_name'])] = true;
                    }
                }
                $indexesResult->free();
            }

            $indexAlterParts = [];
            if (!isset($existingIndexes['finished_batch_id'])) {
                $indexAlterParts[] = "ADD KEY `finished_batch_id` (`finished_batch_id`)";
            }
            if (!isset($existingIndexes['finished_batch_number'])) {
                $indexAlterParts[] = "ADD KEY `finished_batch_number` (`finished_batch_number`)";
            }
            if (!isset($existingIndexes['vehicle_product_unique']) && !isset($existingIndexes['vehicle_product'])) {
                $indexAlterParts[] = "ADD UNIQUE KEY `vehicle_product_unique` (`vehicle_id`, `product_id`)";
            }
            if (!isset($existingIndexes['warehouse_id'])) {
                $indexAlterParts[] = "ADD KEY `warehouse_id` (`warehouse_id`)";
            }
            if (!isset($existingIndexes['product_id'])) {
                $indexAlterParts[] = "ADD KEY `product_id` (`product_id`)";
            }
            if (!isset($existingIndexes['last_updated_by'])) {
                $indexAlterParts[] = "ADD KEY `last_updated_by` (`last_updated_by`)";
            }

            if (!empty($indexAlterParts)) {
                $this->connection->query("ALTER TABLE vehicle_inventory " . implode(', ', $indexAlterParts));
            }

            $this->connection->query(
                "INSERT INTO system_settings (`key`, `value`, updated_at) VALUES ('vehicle_inventory_upgraded', '1', NOW())
                 ON DUPLICATE KEY UPDATE `value` = '1', updated_at = NOW()"
            );

            $flagDir = dirname($flagFile);
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }
            @file_put_contents($flagFile, date('c'));

        } catch (Throwable $upgradeError) {
            error_log('Vehicle inventory auto upgrade error: ' . $upgradeError->getMessage());
        }
    }
}

// دالة مساعدة للحصول على اتصال قاعدة البيانات
function getDB() {
    return Database::getInstance()->getConnection();
}

// دالة مساعدة للحصول على كائن قاعدة البيانات
function db() {
    return Database::getInstance();
}

// ============================================================
// تشغيل الإصلاح التلقائي لمشكلة 262145 (مرة واحدة فقط)
// Auto-fix for 262145 issue (runs only once)
// ============================================================
if (file_exists(__DIR__ . '/auto_fix_262145.php')) {
    define('AUTO_FIX_ALLOWED', true);
    define('RUN_AUTO_FIX', true);
    
    try {
        $autoFixResult = include __DIR__ . '/auto_fix_262145.php';
        
        // تسجيل النتيجة في session للإشعار (اختياري)
        if (is_array($autoFixResult) && isset($autoFixResult['status'])) {
            // يمكن تسجيل النتيجة في log أو session حسب الحاجة
            if ($autoFixResult['status'] === 'completed') {
                error_log("Auto-fix 262145: Successfully completed");
            }
        }
    } catch (Exception $e) {
        // في حالة حدوث خطأ، نسجله فقط ولا نوقف النظام
        error_log("Auto-fix 262145 error: " . $e->getMessage());
    }
}

