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

            // إنشاء جدول جلسات PWA Splash Screen تلقائياً من ملف SQL
            try {
                $tableCheck = $this->connection->query("SHOW TABLES LIKE 'pwa_splash_sessions'");
                if ($tableCheck instanceof mysqli_result && $tableCheck->num_rows === 0) {
                    // قراءة ملف SQL وتنفيذه
                    $migrationFile = __DIR__ . '/../database/migrations/add_pwa_splash_sessions.sql';
                    if (file_exists($migrationFile)) {
                        $sql = file_get_contents($migrationFile);
                        
                        // إزالة التعليقات والمسافات الزائدة
                        $sql = preg_replace('/--.*$/m', '', $sql);
                        $sql = trim($sql);
                        
                        // تنفيذ الاستعلامات
                        if (!empty($sql)) {
                            // تقسيم الاستعلامات إذا كان هناك أكثر من واحد
                            $queries = array_filter(array_map('trim', explode(';', $sql)));
                            foreach ($queries as $query) {
                                if (!empty($query) && !preg_match('/^--/', $query)) {
                                    $this->connection->query($query);
                                }
                            }
                        }
                    } else {
                        // إذا لم يوجد الملف، استخدم الكود المباشر كبديل
                        $this->connection->query("
                            CREATE TABLE IF NOT EXISTS `pwa_splash_sessions` (
                              `id` int(11) NOT NULL AUTO_INCREMENT,
                              `user_id` int(11) DEFAULT NULL,
                              `session_token` varchar(64) NOT NULL,
                              `ip_address` varchar(45) DEFAULT NULL,
                              `user_agent` text DEFAULT NULL,
                              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                              `expires_at` timestamp NOT NULL,
                              PRIMARY KEY (`id`),
                              UNIQUE KEY `session_token` (`session_token`),
                              KEY `user_id` (`user_id`),
                              KEY `expires_at` (`expires_at`),
                              CONSTRAINT `pwa_splash_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                }
                if ($tableCheck instanceof mysqli_result) {
                    $tableCheck->free();
                }
            } catch (Throwable $migrationError) {
                error_log('PWA splash sessions table migration error: ' . $migrationError->getMessage());
            }

            // التحقق من وجود أعمدة created_from_pos و created_by_admin في جدول customers
            // تم تعطيله مؤقتاً لتجنب timeout - يمكن تفعيله لاحقاً بعد إصلاح المشكلة
            // try {
            //     $this->ensureCustomerFlagsMigration();
            // } catch (Throwable $e) {
            //     error_log('Customer flags migration error (non-critical): ' . $e->getMessage());
            // }

            // تم تعطيله مؤقتاً لتجنب timeout
            // $this->ensureVehicleInventoryAutoUpgrade();
            
        } catch (Exception $e) {
            // تسجيل الخطأ في ملف السجل
            error_log("Database connection error: " . $e->getMessage());
            // عرض رسالة الخطأ (للتطوير - سيتم تعطيلها لاحقاً)
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

            // تحديث القيد UNIQUE ليشمل finished_batch_id للسماح بمنتجات من نفس النوع برقم تشغيلة مختلف
            // يجب تنفيذ DROP و ADD في أوامر منفصلة
            $hasOldConstraint = isset($existingIndexes['vehicle_product_unique']) || isset($existingIndexes['vehicle_product']);
            $hasNewConstraint = isset($existingIndexes['vehicle_product_batch_unique']);
            
            if ($hasOldConstraint && !$hasNewConstraint) {
                try {
                    // حذف القيد القديم
                    if (isset($existingIndexes['vehicle_product_unique'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`");
                    }
                    if (isset($existingIndexes['vehicle_product'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`");
                    }
                    // إضافة القيد الجديد الذي يشمل finished_batch_id
                    // في MySQL، يمكن أن يكون هناك عدة صفوف بنفس (vehicle_id, product_id) إذا كان finished_batch_id NULL
                    // ولكن يجب أن يكون هناك صف واحد فقط لكل (vehicle_id, product_id, finished_batch_id) حيث finished_batch_id NOT NULL
                    $this->connection->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
                } catch (Throwable $constraintError) {
                    error_log("Error updating vehicle_inventory unique constraint: " . $constraintError->getMessage());
                }
            } elseif (!$hasNewConstraint && !$hasOldConstraint) {
                // إضافة القيد الجديد مباشرة إذا لم يكن هناك قيد قديم
                try {
                    $this->connection->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
                } catch (Throwable $constraintError) {
                    error_log("Error adding vehicle_inventory unique constraint: " . $constraintError->getMessage());
                }
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
    
    /**
     * التحقق من وجود أعمدة created_from_pos و created_by_admin في جدول customers
     * يتم تشغيلها تلقائياً مرة واحدة فقط
     */
    private function ensureCustomerFlagsMigration(): void
    {
        static $migrationEnsured = false;

        if ($migrationEnsured) {
            return;
        }

        $migrationEnsured = true;

        try {
            $flagFile = dirname(__DIR__) . '/runtime/customer_flags_migration.flag';

            // إذا تم تشغيل الهجرة من قبل، تخطي
            if (file_exists($flagFile)) {
                return;
            }

            $tableCheck = $this->connection->query("SHOW TABLES LIKE 'customers'");
            if (!$tableCheck instanceof mysqli_result || $tableCheck->num_rows === 0) {
                if ($tableCheck instanceof mysqli_result) {
                    $tableCheck->free();
                }
                return;
            }
            $tableCheck->free();

            // التحقق من وجود الأعمدة بسرعة باستخدام استعلامات سريعة
            $createdFromPosCheck = $this->connection->query("SHOW COLUMNS FROM `customers` LIKE 'created_from_pos'");
            $createdByAdminCheck = $this->connection->query("SHOW COLUMNS FROM `customers` LIKE 'created_by_admin'");
            $repIdCheck = $this->connection->query("SHOW COLUMNS FROM `customers` LIKE 'rep_id'");
            
            $needsRepId = !($repIdCheck instanceof mysqli_result && $repIdCheck->num_rows > 0);
            $needsCreatedFromPos = !($createdFromPosCheck instanceof mysqli_result && $createdFromPosCheck->num_rows > 0);
            $needsCreatedByAdmin = !($createdByAdminCheck instanceof mysqli_result && $createdByAdminCheck->num_rows > 0);
            
            if ($repIdCheck instanceof mysqli_result) {
                $repIdCheck->free();
            }
            if ($createdFromPosCheck instanceof mysqli_result) {
                $createdFromPosCheck->free();
            }
            if ($createdByAdminCheck instanceof mysqli_result) {
                $createdByAdminCheck->free();
            }

            if (!$needsRepId && !$needsCreatedFromPos && !$needsCreatedByAdmin) {
                // جميع الأعمدة موجودة، إنشاء flag file وتخطي
                $flagDir = dirname($flagFile);
                if (!is_dir($flagDir)) {
                    @mkdir($flagDir, 0775, true);
                }
                @file_put_contents($flagFile, date('c'));
                return;
            }

            // إضافة الأعمدة واحداً تلو الآخر بالترتيب الصحيح
            if ($needsRepId) {
                $this->connection->query("ALTER TABLE `customers` ADD COLUMN `rep_id` INT NULL AFTER `id`");
            }
            if ($needsCreatedFromPos) {
                $afterColumn = $needsRepId ? 'rep_id' : 'id';
                $this->connection->query("ALTER TABLE `customers` ADD COLUMN `created_from_pos` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$afterColumn}`");
            }
            if ($needsCreatedByAdmin) {
                if (!$needsCreatedFromPos) {
                    $afterColumn = $needsRepId ? 'rep_id' : 'id';
                } else {
                    $afterColumn = 'created_from_pos';
                }
                $this->connection->query("ALTER TABLE `customers` ADD COLUMN `created_by_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `{$afterColumn}`");
            }

            // إضافة مفتاح rep_id إذا تم إضافته
            if ($needsRepId) {
                try {
                    $indexCheck = $this->connection->query("SHOW INDEX FROM `customers` WHERE Key_name = 'rep_id'");
                    $hasIndex = ($indexCheck instanceof mysqli_result && $indexCheck->num_rows > 0);
                    if ($indexCheck instanceof mysqli_result) {
                        $indexCheck->free();
                    }
                    if (!$hasIndex) {
                        $this->connection->query("ALTER TABLE `customers` ADD KEY `rep_id` (`rep_id`)");
                    }
                } catch (Throwable $indexError) {
                    error_log('Customers rep_id index migration error: ' . $indexError->getMessage());
                }

                // تحديث rep_id للعملاء الموجودين - تم تعطيله لتجنب timeout
                // يمكن تشغيله لاحقاً عبر cron job أو يدوياً
                // try {
                //     $this->connection->query("
                //         UPDATE customers c
                //         INNER JOIN users u ON c.rep_id IS NULL AND c.created_by = u.id AND u.role = 'sales'
                //         SET c.rep_id = u.id
                //     ");
                // } catch (Throwable $updateError) {
                //     error_log('Customers rep_id update error (non-critical): ' . $updateError->getMessage());
                // }
            }

            // إنشاء flag file للإشارة إلى اكتمال الهجرة
            $flagDir = dirname($flagFile);
            if (!is_dir($flagDir)) {
                @mkdir($flagDir, 0775, true);
            }
            @file_put_contents($flagFile, date('c'));

        } catch (Throwable $customerFlagsError) {
            error_log('Customers flags migration error: ' . $customerFlagsError->getMessage());
        }
    }
    
    /**
     * تحديث قيد UNIQUE في vehicle_inventory ليشمل finished_batch_id
     * يتم استدعاؤها تلقائياً عند الاتصال بقاعدة البيانات
     */
    private function updateVehicleInventoryUniqueConstraint(): void
    {
        static $updated = false;
        
        if ($updated) {
            return;
        }
        
        try {
            // التحقق من وجود الجدول
            $tableExists = $this->connection->query("SHOW TABLES LIKE 'vehicle_inventory'");
            if (!$tableExists instanceof mysqli_result || $tableExists->num_rows === 0) {
                return;
            }
            $tableExists->free();
            
            // الحصول على الفهارس الموجودة
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
            
            $hasOldConstraint = isset($existingIndexes['vehicle_product_unique']) || isset($existingIndexes['vehicle_product']);
            $hasNewConstraint = isset($existingIndexes['vehicle_product_batch_unique']);
            
            // إذا كان القيد الجديد موجود بالفعل، لا حاجة للتحديث
            if ($hasNewConstraint) {
                $updated = true;
                return;
            }
            
            // حذف القيد القديم وإضافة الجديد
            if ($hasOldConstraint) {
                try {
                    if (isset($existingIndexes['vehicle_product_unique'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product_unique`");
                    }
                    if (isset($existingIndexes['vehicle_product'])) {
                        $this->connection->query("ALTER TABLE vehicle_inventory DROP INDEX `vehicle_product`");
                    }
                } catch (Throwable $dropError) {
                    error_log("Error dropping old constraint: " . $dropError->getMessage());
                }
            }
            
            // إضافة القيد الجديد
            try {
                $this->connection->query("ALTER TABLE vehicle_inventory ADD UNIQUE KEY `vehicle_product_batch_unique` (`vehicle_id`, `product_id`, `finished_batch_id`)");
                $updated = true;
            } catch (Throwable $addError) {
                // قد يكون القيد موجود بالفعل أو هناك مشكلة أخرى
                error_log("Error adding new constraint: " . $addError->getMessage());
            }
            
        } catch (Throwable $e) {
            error_log("Error in updateVehicleInventoryUniqueConstraint: " . $e->getMessage());
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