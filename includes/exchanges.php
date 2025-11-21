<?php
/**
 * نظام الاستبدال
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/inventory_movements.php';

/**
 * التأكد من وجود جداول الاستبدال المطلوبة
 */
function ensureExchangeSchema(): void
{
    $db = db();

    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
    if (!empty($tableCheck)) {
        return;
    }

    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `exchanges` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `exchange_number` varchar(50) NOT NULL,
              `return_id` int(11) DEFAULT NULL,
              `original_sale_id` int(11) NOT NULL,
              `customer_id` int(11) NOT NULL,
              `sales_rep_id` int(11) DEFAULT NULL,
              `exchange_date` date NOT NULL,
              `exchange_type` enum('same_product','different_product','upgrade','downgrade') DEFAULT 'same_product',
              `reason` text DEFAULT NULL,
              `original_total` decimal(15,2) NOT NULL,
              `new_total` decimal(15,2) NOT NULL,
              `difference_amount` decimal(15,2) DEFAULT 0.00,
              `difference_paid` tinyint(1) DEFAULT 0,
              `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
              `approved_by` int(11) DEFAULT NULL,
              `approved_at` timestamp NULL DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `exchange_number` (`exchange_number`),
              KEY `return_id` (`return_id`),
              KEY `original_sale_id` (`original_sale_id`),
              KEY `customer_id` (`customer_id`),
              KEY `sales_rep_id` (`sales_rep_id`),
              KEY `exchange_date` (`exchange_date`),
              KEY `status` (`status`),
              KEY `approved_by` (`approved_by`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $returnItemsCheck = $db->queryOne("SHOW TABLES LIKE 'exchange_return_items'");
        if (empty($returnItemsCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `exchange_return_items` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `exchange_id` int(11) NOT NULL,
                  `product_id` int(11) NOT NULL,
                  `quantity` decimal(10,2) NOT NULL,
                  `unit_price` decimal(15,2) NOT NULL,
                  `total_price` decimal(15,2) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `exchange_id` (`exchange_id`),
                  KEY `product_id` (`product_id`),
                  CONSTRAINT `exchange_return_items_ibfk_1` FOREIGN KEY (`exchange_id`) REFERENCES `exchanges` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `exchange_return_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        $newItemsCheck = $db->queryOne("SHOW TABLES LIKE 'exchange_new_items'");
        if (empty($newItemsCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `exchange_new_items` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `exchange_id` int(11) NOT NULL,
                  `product_id` int(11) NOT NULL,
                  `quantity` decimal(10,2) NOT NULL,
                  `unit_price` decimal(15,2) NOT NULL,
                  `total_price` decimal(15,2) NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `exchange_id` (`exchange_id`),
                  KEY `product_id` (`product_id`),
                  CONSTRAINT `exchange_new_items_ibfk_1` FOREIGN KEY (`exchange_id`) REFERENCES `exchanges` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `exchange_new_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }

        try {
            $db->execute("
                ALTER TABLE `exchanges`
                ADD CONSTRAINT `exchanges_ibfk_2` FOREIGN KEY (`original_sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
                ADD CONSTRAINT `exchanges_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
            ");
        } catch (Exception $e) {
            error_log("Foreign key constraint may already exist: " . $e->getMessage());
        }

        try {
            if ($db->queryOne("SHOW COLUMNS FROM exchanges LIKE 'return_id'")) {
                $db->execute("
                    ALTER TABLE `exchanges`
                    ADD CONSTRAINT `exchanges_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL
                ");
            }
        } catch (Exception $e) {
            error_log("Foreign key constraint may already exist: " . $e->getMessage());
        }

        try {
            $db->execute("
                ALTER TABLE `exchanges`
                ADD CONSTRAINT `exchanges_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                ADD CONSTRAINT `exchanges_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                ADD CONSTRAINT `exchanges_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ");
        } catch (Exception $e) {
            error_log("Foreign key constraint may already exist: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("Error creating exchanges table: " . $e->getMessage());
    }
}

/**
 * توليد رقم استبدال
 */
function generateExchangeNumber() {
    $db = db();
    $prefix = 'EXC-' . date('Ym');
    $lastExchange = $db->queryOne(
        "SELECT exchange_number FROM exchanges WHERE exchange_number LIKE ? ORDER BY exchange_number DESC LIMIT 1",
        [$prefix . '%']
    );
    
    $serial = 1;
    if ($lastExchange) {
        $parts = explode('-', $lastExchange['exchange_number']);
        $serial = intval($parts[2] ?? 0) + 1;
    }
    
    return sprintf("%s-%04d", $prefix, $serial);
}

/**
 * إنشاء استبدال جديد
 */
function createExchange($originalSaleId, $returnId, $customerId, $salesRepId, $exchangeDate,
                       $exchangeType, $reason, $returnItems, $newItems, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        if (!$createdBy) {
            return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
        }
        
        $exchangeNumber = generateExchangeNumber();
        
        // حساب المبالغ
        $originalTotal = 0;
        foreach ($returnItems as $item) {
            $originalTotal += ($item['quantity'] * $item['unit_price']);
        }
        
        $newTotal = 0;
        foreach ($newItems as $item) {
            $newTotal += ($item['quantity'] * $item['unit_price']);
        }
        
        $differenceAmount = $newTotal - $originalTotal;
        
        $db->execute(
            "INSERT INTO exchanges 
            (exchange_number, return_id, original_sale_id, customer_id, sales_rep_id, 
             exchange_date, exchange_type, reason, original_total, new_total, 
             difference_amount, difference_paid, status, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending', ?)",
            [
                $exchangeNumber,
                $returnId,
                $originalSaleId,
                $customerId,
                $salesRepId,
                $exchangeDate,
                $exchangeType,
                $reason,
                $originalTotal,
                $newTotal,
                $differenceAmount,
                $createdBy
            ]
        );
        
        $exchangeId = $db->getLastInsertId();
        
        // إضافة المنتجات المرتجعة
        foreach ($returnItems as $item) {
            $db->execute(
                "INSERT INTO exchange_return_items 
                (exchange_id, product_id, quantity, unit_price, total_price) 
                VALUES (?, ?, ?, ?, ?)",
                [
                    $exchangeId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['quantity'] * $item['unit_price']
                ]
            );
        }
        
        // إضافة المنتجات الجديدة
        foreach ($newItems as $item) {
            $db->execute(
                "INSERT INTO exchange_new_items 
                (exchange_id, product_id, quantity, unit_price, total_price) 
                VALUES (?, ?, ?, ?, ?)",
                [
                    $exchangeId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['quantity'] * $item['unit_price']
                ]
            );
        }
        
        // إرسال إشعار للمديرين للموافقة
        notifyManagers(
            'استبدال جديد',
            "تم إنشاء استبدال جديد رقم {$exchangeNumber}",
            'info',
            "dashboard/manager.php?page=returns&section=exchanges&id={$exchangeId}"
        );
        
        logAudit($createdBy, 'create_exchange', 'exchange', $exchangeId, null, [
            'exchange_number' => $exchangeNumber,
            'difference_amount' => $differenceAmount
        ]);
        
        return ['success' => true, 'exchange_id' => $exchangeId, 'exchange_number' => $exchangeNumber];
        
    } catch (Exception $e) {
        error_log("Exchange Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء الاستبدال'];
    }
}

/**
 * الموافقة على استبدال
 */
function approveExchange($exchangeId, $approvedBy = null) {
    try {
        $db = db();
        
        if ($approvedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $approvedBy = $currentUser['id'] ?? null;
        }
        
        $exchange = $db->queryOne("SELECT * FROM exchanges WHERE id = ?", [$exchangeId]);
        
        if (!$exchange) {
            return ['success' => false, 'message' => 'الاستبدال غير موجود'];
        }
        
        if ($exchange['status'] !== 'pending') {
            return ['success' => false, 'message' => 'تم معالجة هذا الاستبدال بالفعل'];
        }
        
        $db->getConnection()->begin_transaction();
        
        // تحديث حالة الاستبدال
        $db->execute(
            "UPDATE exchanges 
             SET status = 'approved', approved_by = ?, approved_at = NOW() 
             WHERE id = ?",
            [$approvedBy, $exchangeId]
        );
        
        // إرجاع المنتجات القديمة للمخزون
        $returnItems = $db->query(
            "SELECT * FROM exchange_return_items WHERE exchange_id = ?",
            [$exchangeId]
        );
        
        foreach ($returnItems as $item) {
            recordInventoryMovement(
                $item['product_id'],
                'in',
                $item['quantity'],
                null,
                'exchange',
                $exchangeId,
                "إرجاع من استبدال رقم {$exchange['exchange_number']}",
                $approvedBy
            );
        }
        
        // خروج المنتجات الجديدة من المخزون
        $newItems = $db->query(
            "SELECT * FROM exchange_new_items WHERE exchange_id = ?",
            [$exchangeId]
        );
        
        foreach ($newItems as $item) {
            recordInventoryMovement(
                $item['product_id'],
                'out',
                $item['quantity'],
                null,
                'exchange',
                $exchangeId,
                "استبدال رقم {$exchange['exchange_number']}",
                $approvedBy
            );
        }
        
        $db->getConnection()->commit();
        
        logAudit($approvedBy, 'approve_exchange', 'exchange', $exchangeId, 
                 ['old_status' => $exchange['status']], 
                 ['new_status' => 'approved']);
        
        return ['success' => true, 'message' => 'تم الموافقة على الاستبدال بنجاح'];
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        error_log("Exchange Approval Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في الموافقة على الاستبدال'];
    }
}

/**
 * الحصول على الاستبدالات
 */
function getExchanges($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    // التحقق من وجود عمود sale_number في جدول sales
    $saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
    $hasSaleNumberColumn = !empty($saleNumberColumnCheck);
    
    // بناء SELECT بشكل ديناميكي
    $selectColumns = ['e.*'];
    if ($hasSaleNumberColumn) {
        $selectColumns[] = 's.sale_number';
    } else {
        $selectColumns[] = 's.id as sale_number';
    }
    $selectColumns[] = 'c.name as customer_name';
    $selectColumns[] = 'u.full_name as sales_rep_name';
    $selectColumns[] = 'u2.full_name as approved_by_name';
    
    $sql = "SELECT " . implode(', ', $selectColumns) . "
            FROM exchanges e
            LEFT JOIN sales s ON e.original_sale_id = s.id
            LEFT JOIN customers c ON e.customer_id = c.id
            LEFT JOIN users u ON e.sales_rep_id = u.id
            LEFT JOIN users u2 ON e.approved_by = u2.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND e.customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['sales_rep_id'])) {
        $sql .= " AND e.sales_rep_id = ?";
        $params[] = $filters['sales_rep_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND e.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(e.exchange_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(e.exchange_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY e.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

