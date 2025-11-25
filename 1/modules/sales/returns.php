<?php
/**
 * صفحة إدارة المرتجعات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/returns.php';
require_once __DIR__ . '/../../includes/exchanges.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$section = $_POST['section'] ?? ($_GET['section'] ?? 'returns');
$section = in_array($section, ['returns', 'exchanges'], true) ? $section : 'returns';
$error = '';
$success = '';
$exchangeError = '';
$exchangeSuccess = '';

ensureExchangeSchema();

// التحقق من وجود جدول returns وإنشاؤه إذا لم يكن موجوداً
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'returns'");
if (empty($tableCheck)) {
    try {
        // إنشاء جدول returns
        $db->execute("
            CREATE TABLE IF NOT EXISTS `returns` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `return_number` varchar(50) NOT NULL,
              `sale_id` int(11) NOT NULL,
              `invoice_id` int(11) DEFAULT NULL,
              `customer_id` int(11) NOT NULL,
              `sales_rep_id` int(11) DEFAULT NULL,
              `return_date` date NOT NULL,
              `return_type` enum('full','partial') DEFAULT 'full',
              `reason` enum('defective','wrong_item','customer_request','other') DEFAULT 'customer_request',
              `reason_description` text DEFAULT NULL,
              `refund_amount` decimal(15,2) DEFAULT 0.00,
              `refund_method` enum('cash','credit','exchange') DEFAULT 'cash',
              `status` enum('pending','approved','rejected','processed') DEFAULT 'pending',
              `approved_by` int(11) DEFAULT NULL,
              `approved_at` timestamp NULL DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `return_number` (`return_number`),
              KEY `sale_id` (`sale_id`),
              KEY `invoice_id` (`invoice_id`),
              KEY `customer_id` (`customer_id`),
              KEY `sales_rep_id` (`sales_rep_id`),
              KEY `return_date` (`return_date`),
              KEY `status` (`status`),
              KEY `approved_by` (`approved_by`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // التحقق من وجود جدول return_items وإنشاؤه إذا لم يكن موجوداً
        $itemsTableCheck = $db->queryOne("SHOW TABLES LIKE 'return_items'");
        if (empty($itemsTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `return_items` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `return_id` int(11) NOT NULL,
                  `sale_item_id` int(11) DEFAULT NULL,
                  `product_id` int(11) NOT NULL,
                  `quantity` decimal(10,2) NOT NULL,
                  `unit_price` decimal(15,2) NOT NULL,
                  `total_price` decimal(15,2) NOT NULL,
                  `condition` enum('new','used','damaged','defective') DEFAULT 'new',
                  `notes` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `return_id` (`return_id`),
                  KEY `product_id` (`product_id`),
                  CONSTRAINT `return_items_ibfk_1` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `return_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        // إضافة Foreign Keys إذا لم تكن موجودة (بعد إنشاء الجداول)
        try {
            $db->execute("
                ALTER TABLE `returns`
                ADD CONSTRAINT `returns_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
                ADD CONSTRAINT `returns_ibfk_3` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
            ");
        } catch (Exception $e) {
            // Foreign key قد يكون موجوداً بالفعل، تجاهل الخطأ
            error_log("Foreign key constraint may already exist: " . $e->getMessage());
        }
        
        try {
            if ($db->queryOne("SHOW COLUMNS FROM returns LIKE 'invoice_id'")) {
                $db->execute("
                    ALTER TABLE `returns`
                    ADD CONSTRAINT `returns_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
                ");
            }
        } catch (Exception $e) {
            error_log("Foreign key constraint may already exist: " . $e->getMessage());
        }
        
        try {
            $db->execute("
                ALTER TABLE `returns`
                ADD CONSTRAINT `returns_ibfk_4` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                ADD CONSTRAINT `returns_ibfk_5` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                ADD CONSTRAINT `returns_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ");
        } catch (Exception $e) {
            error_log("Foreign key constraint may already exist: " . $e->getMessage());
        }
        
    } catch (Exception $e) {
        error_log("Error creating returns table: " . $e->getMessage());
        $error = 'حدث خطأ في إنشاء الجدول المطلوب. يرجى التحقق من قاعدة البيانات.';
    }
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$filters = [
    'customer_id' => $_GET['customer_id'] ?? '',
    'sales_rep_id' => $_GET['sales_rep_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

if ($currentUser['role'] === 'sales') {
    $filters['sales_rep_id'] = $currentUser['id'];
}

$filters = array_filter($filters, function($value) {
    return $value !== '';
});

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_return') {
        $section = 'returns';
        $saleId = intval($_POST['sale_id'] ?? 0);
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : $currentUser['id'];
        $returnDate = $_POST['return_date'] ?? date('Y-m-d');
        $returnType = $_POST['return_type'] ?? 'full';
        $reason = $_POST['reason'] ?? 'customer_request';
        $reasonDescription = trim($_POST['reason_description'] ?? '');
        $refundMethod = $_POST['refund_method'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');
        
        // معالجة العناصر
        $items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $items[] = [
                        'product_id' => intval($item['product_id']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price']),
                        'condition' => $item['condition'] ?? 'new',
                        'notes' => trim($item['notes'] ?? '')
                    ];
                }
            }
        }
        
        if ($saleId <= 0 || $customerId <= 0 || empty($items)) {
            $error = 'يجب إدخال جميع البيانات المطلوبة';
        } else {
            $result = createReturn($saleId, $customerId, $salesRepId, $returnDate, $returnType, 
                                  $reason, $reasonDescription, $items, $refundMethod, $notes);
            if ($result['success']) {
                $success = 'تم إنشاء المرتجع بنجاح: ' . $result['return_number'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'approve_return') {
        $returnId = intval($_POST['return_id'] ?? 0);
        
        if ($returnId > 0) {
            $result = approveReturn($returnId);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
    } elseif ($action === 'create_exchange') {
        $section = 'exchanges';
        $originalSaleId = intval($_POST['original_sale_id'] ?? 0);
        $returnId = !empty($_POST['return_id']) ? intval($_POST['return_id']) : null;
        $customerId = intval($_POST['customer_id'] ?? 0);
        $salesRepId = !empty($_POST['sales_rep_id']) ? intval($_POST['sales_rep_id']) : $currentUser['id'];
        $exchangeDate = $_POST['exchange_date'] ?? date('Y-m-d');
        $exchangeType = $_POST['exchange_type'] ?? 'same_product';
        $reason = trim($_POST['reason'] ?? '');

        $returnItems = [];
        if (!empty($_POST['return_items']) && is_array($_POST['return_items'])) {
            foreach ($_POST['return_items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $returnItems[] = [
                        'product_id' => intval($item['product_id']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }
        }

        $newItems = [];
        if (!empty($_POST['new_items']) && is_array($_POST['new_items'])) {
            foreach ($_POST['new_items'] as $item) {
                if (!empty($item['product_id']) && $item['quantity'] > 0 && $item['unit_price'] > 0) {
                    $newItems[] = [
                        'product_id' => intval($item['product_id']),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
            }
        }

        if ($originalSaleId <= 0 || $customerId <= 0 || empty($returnItems) || empty($newItems)) {
            $exchangeError = 'يجب إدخال جميع البيانات المطلوبة';
        } else {
            $result = createExchange($originalSaleId, $returnId, $customerId, $salesRepId, $exchangeDate, $exchangeType, $reason, $returnItems, $newItems);
            if ($result['success']) {
                $exchangeSuccess = 'تم إنشاء الاستبدال بنجاح: ' . $result['exchange_number'];
            } else {
                $exchangeError = $result['message'] ?? 'حدث خطأ في إنشاء الاستبدال';
            }
        }
    } elseif ($action === 'approve_exchange') {
        $section = 'exchanges';
        $exchangeId = intval($_POST['exchange_id'] ?? 0);

        if ($exchangeId > 0) {
            $result = approveExchange($exchangeId);
            if ($result['success']) {
                $exchangeSuccess = $result['message'];
            } else {
                $exchangeError = $result['message'];
            }
        }
    }
}

// الحصول على البيانات - حساب العدد الإجمالي مع الفلترة
$countSql = "SELECT COUNT(*) as total FROM returns r WHERE 1=1";
$countParams = [];

if ($currentUser['role'] === 'sales') {
    $countSql .= " AND r.sales_rep_id = ?";
    $countParams[] = $currentUser['id'];
}

if (!empty($filters['customer_id'])) {
    $countSql .= " AND r.customer_id = ?";
    $countParams[] = $filters['customer_id'];
}

if (!empty($filters['sales_rep_id'])) {
    $countSql .= " AND r.sales_rep_id = ?";
    $countParams[] = $filters['sales_rep_id'];
}

if (!empty($filters['status'])) {
    $countSql .= " AND r.status = ?";
    $countParams[] = $filters['status'];
}

if (!empty($filters['date_from'])) {
    $countSql .= " AND DATE(r.return_date) >= ?";
    $countParams[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $countSql .= " AND DATE(r.return_date) <= ?";
    $countParams[] = $filters['date_to'];
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalReturns = $totalResult['total'] ?? 0;
$totalPages = ceil($totalReturns / $perPage);
$returns = getReturns($filters, $perPage, $offset);

$customers = $db->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name");

// التحقق من وجود عمود sale_number في جدول sales
$saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
$hasSaleNumberColumn = !empty($saleNumberColumnCheck);

// بناء استعلام المبيعات بشكل ديناميكي
if ($hasSaleNumberColumn) {
    $sales = $db->query(
        "SELECT s.id, s.sale_number, c.name as customer_name 
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE s.status = 'approved'
         ORDER BY s.created_at DESC LIMIT 50"
    );
} else {
    $sales = $db->query(
        "SELECT s.id, s.id as sale_number, c.name as customer_name 
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE s.status = 'approved'
         ORDER BY s.created_at DESC LIMIT 50"
    );
}

$products = $db->query("SELECT id, name, unit_price FROM products WHERE status = 'active' ORDER BY name");

// إعداد فلاتر الاستبدال
$exchangeFiltersRaw = [
    'customer_id' => $_GET['exchange_customer_id'] ?? '',
    'sales_rep_id' => $_GET['exchange_sales_rep_id'] ?? '',
    'status' => $_GET['exchange_status'] ?? '',
    'date_from' => $_GET['exchange_date_from'] ?? '',
    'date_to' => $_GET['exchange_date_to'] ?? ''
];

if ($currentUser['role'] === 'sales') {
    $exchangeFiltersRaw['sales_rep_id'] = $currentUser['id'];
}

$exchangeFilters = array_filter($exchangeFiltersRaw, static function ($value) {
    return $value !== '';
});

$exchangeQueryParams = array_filter([
    'exchange_customer_id' => $_GET['exchange_customer_id'] ?? '',
    'exchange_sales_rep_id' => $_GET['exchange_sales_rep_id'] ?? '',
    'exchange_status' => $_GET['exchange_status'] ?? '',
    'exchange_date_from' => $_GET['exchange_date_from'] ?? '',
    'exchange_date_to' => $_GET['exchange_date_to'] ?? ''
], static function ($value) {
    return $value !== '';
});

$exchangePageNum = isset($_GET['exch_p']) ? max(1, intval($_GET['exch_p'])) : 1;
$exchangePerPage = 20;
$exchangeOffset = ($exchangePageNum - 1) * $exchangePerPage;

$exchangeTableExists = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
if (!empty($exchangeTableExists)) {
    $exchangeCountSql = "SELECT COUNT(*) as total FROM exchanges e WHERE 1=1";
    $exchangeCountParams = [];

    if ($currentUser['role'] === 'sales') {
        $exchangeCountSql .= " AND e.sales_rep_id = ?";
        $exchangeCountParams[] = $currentUser['id'];
    }

    if (!empty($exchangeFilters['customer_id'])) {
        $exchangeCountSql .= " AND e.customer_id = ?";
        $exchangeCountParams[] = $exchangeFilters['customer_id'];
    }

    if (!empty($exchangeFilters['sales_rep_id'])) {
        $exchangeCountSql .= " AND e.sales_rep_id = ?";
        $exchangeCountParams[] = $exchangeFilters['sales_rep_id'];
    }

    if (!empty($exchangeFilters['status'])) {
        $exchangeCountSql .= " AND e.status = ?";
        $exchangeCountParams[] = $exchangeFilters['status'];
    }

    if (!empty($exchangeFilters['date_from'])) {
        $exchangeCountSql .= " AND DATE(e.exchange_date) >= ?";
        $exchangeCountParams[] = $exchangeFilters['date_from'];
    }

    if (!empty($exchangeFilters['date_to'])) {
        $exchangeCountSql .= " AND DATE(e.exchange_date) <= ?";
        $exchangeCountParams[] = $exchangeFilters['date_to'];
    }

    $exchangeTotalResult = $db->queryOne($exchangeCountSql, $exchangeCountParams);
    $exchangeTotal = $exchangeTotalResult['total'] ?? 0;
    $exchangeTotalPages = $exchangeTotal > 0 ? (int)ceil($exchangeTotal / $exchangePerPage) : 0;
    $exchangeList = getExchanges($exchangeFilters, $exchangePerPage, $exchangeOffset);
} else {
    $exchangeTotal = 0;
    $exchangeTotalPages = 0;
    $exchangeList = [];
    if ($exchangeError === '') {
        $exchangeError = 'جدول الاستبدالات غير موجود. يرجى التحقق من قاعدة البيانات.';
    }
}

// استبدال محدد للعرض
$selectedExchange = null;
if (isset($_GET['exchange_id'])) {
    $selectedExchangeId = intval($_GET['exchange_id']);
    if ($selectedExchangeId > 0) {
        $selectedExchange = $db->queryOne(
            "SELECT e.*, " . ($hasSaleNumberColumn ? "s.sale_number" : "s.id as sale_number") . ",
                    c.name as customer_name,
                    u.full_name as sales_rep_name,
                    u2.full_name as approved_by_name
             FROM exchanges e
             LEFT JOIN sales s ON e.original_sale_id = s.id
             LEFT JOIN customers c ON e.customer_id = c.id
             LEFT JOIN users u ON e.sales_rep_id = u.id
             LEFT JOIN users u2 ON e.approved_by = u2.id
             WHERE e.id = ?",
            [$selectedExchangeId]
        );

        if ($selectedExchange) {
            $selectedExchange['return_items'] = $db->query(
                "SELECT eri.*, p.name as product_name
                 FROM exchange_return_items eri
                 LEFT JOIN products p ON eri.product_id = p.id
                 WHERE eri.exchange_id = ?
                 ORDER BY eri.id",
                [$selectedExchangeId]
            );

            $selectedExchange['new_items'] = $db->query(
                "SELECT eni.*, p.name as product_name
                 FROM exchange_new_items eni
                 LEFT JOIN products p ON eni.product_id = p.id
                 WHERE eni.exchange_id = ?
                 ORDER BY eni.id",
                [$selectedExchangeId]
            );
        }
    }
}

// مرتجع محدد للعرض
$selectedReturn = null;
if (isset($_GET['id'])) {
    $returnId = intval($_GET['id']);
    
    // بناء SELECT بشكل ديناميكي
    $selectColumns = ['r.*'];
    if ($hasSaleNumberColumn) {
        $selectColumns[] = 's.sale_number';
    } else {
        $selectColumns[] = 's.id as sale_number';
    }
    $selectColumns[] = 'c.name as customer_name';
    $selectColumns[] = 'c.phone as customer_phone';
    $selectColumns[] = 'u.full_name as sales_rep_name';
    $selectColumns[] = 'u2.full_name as approved_by_name';
    
    $selectedReturn = $db->queryOne(
        "SELECT " . implode(', ', $selectColumns) . "
         FROM returns r
         LEFT JOIN sales s ON r.sale_id = s.id
         LEFT JOIN customers c ON r.customer_id = c.id
         LEFT JOIN users u ON r.sales_rep_id = u.id
         LEFT JOIN users u2 ON r.approved_by = u2.id
         WHERE r.id = ?",
        [$returnId]
    );
    
    if ($selectedReturn) {
        $selectedReturn['items'] = $db->query(
            "SELECT ri.*, p.name as product_name
             FROM return_items ri
             LEFT JOIN products p ON ri.product_id = p.id
             WHERE ri.return_id = ?
             ORDER BY ri.id",
            [$returnId]
        );
    }
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h2 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>إدارة المرتجعات والاستبدال</h2>
    <div class="btn-group" role="group" aria-label="Returns and exchanges sections">
        <a href="?page=returns&section=returns" class="btn btn-sm <?php echo $section === 'returns' ? 'btn-primary' : 'btn-outline-primary'; ?>">
            <i class="bi bi-arrow-return-left me-1"></i>المرتجعات
        </a>
        <a href="?page=returns&section=exchanges" class="btn btn-sm <?php echo $section === 'exchanges' ? 'btn-primary' : 'btn-outline-primary'; ?>">
            <i class="bi bi-arrow-repeat me-1"></i>الاستبدال
        </a>
    </div>
</div>

<?php if ($section === 'returns'): ?>
<?php if (hasRole(['sales', 'accountant'])): ?>
<div class="mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addReturnModal">
        <i class="bi bi-plus-circle me-2"></i>إنشاء مرتجع جديد
    </button>
</div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($selectedReturn): ?>
    <!-- عرض مرتجع محدد -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">مرتجع رقم: <?php echo htmlspecialchars($selectedReturn['return_number']); ?></h5>
            <a href="?page=returns&section=returns" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">العميل:</th>
                            <td><?php echo htmlspecialchars($selectedReturn['customer_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>رقم البيع:</th>
                            <td><?php echo htmlspecialchars($selectedReturn['sale_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ المرتجع:</th>
                            <td><?php echo formatDate($selectedReturn['return_date']); ?></td>
                        </tr>
                        <tr>
                            <th>نوع المرتجع:</th>
                            <td><?php echo $selectedReturn['return_type'] === 'full' ? 'كامل' : 'جزئي'; ?></td>
                        </tr>
                        <tr>
                            <th>السبب:</th>
                            <td>
                                <?php 
                                $reasons = [
                                    'defective' => 'منتج معيب',
                                    'wrong_item' => 'منتج خاطئ',
                                    'customer_request' => 'طلب العميل',
                                    'other' => 'أخرى'
                                ];
                                echo $reasons[$selectedReturn['reason']] ?? $selectedReturn['reason'];
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedReturn['status'] === 'processed' ? 'success' : 
                                        ($selectedReturn['status'] === 'rejected' ? 'danger' : 
                                        ($selectedReturn['status'] === 'approved' ? 'info' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'approved' => 'موافق عليه',
                                        'rejected' => 'مرفوض',
                                        'processed' => 'تمت المعالجة'
                                    ];
                                    echo $statuses[$selectedReturn['status']] ?? $selectedReturn['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>مبلغ الاسترداد:</th>
                            <td><strong><?php echo formatCurrency($selectedReturn['refund_amount']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>طريقة الاسترداد:</th>
                            <td>
                                <?php 
                                $methods = [
                                    'cash' => 'نقدي',
                                    'credit' => 'رصيد',
                                    'exchange' => 'استبدال'
                                ];
                                echo $methods[$selectedReturn['refund_method']] ?? $selectedReturn['refund_method'];
                                ?>
                            </td>
                        </tr>
                        <?php if ($selectedReturn['approved_by_name']): ?>
                        <tr>
                            <th>تمت الموافقة بواسطة:</th>
                            <td><?php echo htmlspecialchars($selectedReturn['approved_by_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
            
            <?php if (!empty($selectedReturn['items'])): ?>
                <h6 class="mt-3">عناصر المرتجع:</h6>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--compact align-middle">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>الكمية</th>
                                <th>سعر الوحدة</th>
                                <th>الإجمالي</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedReturn['items'] as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                    <td><?php echo number_format($item['quantity'], 2); ?></td>
                                    <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                    <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    <td>
                                        <?php 
                                        $conditions = [
                                            'new' => 'جديد',
                                            'used' => 'مستعمل',
                                            'damaged' => 'تالف',
                                            'defective' => 'معيب'
                                        ];
                                        echo $conditions[$item['condition']] ?? $item['condition'];
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <?php if ($selectedReturn['reason_description']): ?>
                <div class="mt-3">
                    <h6>وصف السبب:</h6>
                    <p><?php echo nl2br(htmlspecialchars($selectedReturn['reason_description'])); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($selectedReturn['notes']): ?>
                <div class="mt-3">
                    <h6>ملاحظات:</h6>
                    <p><?php echo nl2br(htmlspecialchars($selectedReturn['notes'])); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ($selectedReturn['status'] === 'pending' && hasRole('manager')): ?>
                <div class="mt-3">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="section" value="returns">
                        <input type="hidden" name="action" value="approve_return">
                        <input type="hidden" name="return_id" value="<?php echo $selectedReturn['id']; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>الموافقة على المرتجع
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="returns">
            <input type="hidden" name="section" value="returns">
            <div class="col-md-3">
                <label class="form-label">العميل</label>
                <select class="form-select" name="customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedCustomerId = isset($filters['customer_id']) ? intval($filters['customer_id']) : 0;
                    $customerValid = isValidSelectValue($selectedCustomerId, $customers, 'id');
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" 
                                <?php echo $customerValid && $selectedCustomerId == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($filters['status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($filters['status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                    <option value="processed" <?php echo ($filters['status'] ?? '') === 'processed' ? 'selected' : ''; ?>>تمت المعالجة</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة المرتجعات -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة المرتجعات (<?php echo $totalReturns; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم المرتجع</th>
                        <th>العميل</th>
                        <th>تاريخ المرتجع</th>
                        <th>مبلغ الاسترداد</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($returns)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">لا توجد مرتجعات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($returns as $return): ?>
                            <tr>
                                <td>
                                    <a href="?page=returns&section=returns&id=<?php echo $return['id']; ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($return['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($return['return_date']); ?></td>
                                <td><?php echo formatCurrency($return['refund_amount']); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $return['status'] === 'processed' ? 'success' : 
                                            ($return['status'] === 'rejected' ? 'danger' : 
                                            ($return['status'] === 'approved' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'processed' => 'تمت المعالجة'
                                        ];
                                        echo $statuses[$return['status']] ?? $return['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?page=returns&section=returns&id=<?php echo $return['id']; ?>" 
                                       class="btn btn-info btn-sm" title="عرض">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'returns', 'p' => $pageNum - 1], $filters)); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'returns', 'p' => $i], $filters)); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'returns', 'p' => $pageNum + 1], $filters)); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إنشاء مرتجع -->
<?php if (hasRole(['sales', 'accountant'])): ?>
<div class="modal fade" id="addReturnModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إنشاء مرتجع جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="returnForm">
                <input type="hidden" name="action" value="create_return">
                <input type="hidden" name="section" value="returns">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">رقم البيع <span class="text-danger">*</span></label>
                            <select class="form-select" name="sale_id" id="saleSelect" required>
                                <option value="">اختر البيع</option>
                                <?php foreach ($sales as $sale): ?>
                                    <option value="<?php echo $sale['id']; ?>" 
                                            data-customer="<?php echo $sale['customer_name']; ?>">
                                        <?php echo htmlspecialchars($sale['sale_number']); ?> - 
                                        <?php echo htmlspecialchars($sale['customer_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">العميل <span class="text-danger">*</span></label>
                            <input type="hidden" name="customer_id" id="customerId">
                            <input type="text" class="form-control" id="customerName" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">تاريخ المرتجع <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="return_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">نوع المرتجع</label>
                            <select class="form-select" name="return_type">
                                <option value="full">كامل</option>
                                <option value="partial">جزئي</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">السبب</label>
                            <select class="form-select" name="reason">
                                <option value="customer_request">طلب العميل</option>
                                <option value="defective">منتج معيب</option>
                                <option value="wrong_item">منتج خاطئ</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">طريقة الاسترداد</label>
                            <select class="form-select" name="refund_method">
                                <option value="cash">نقدي</option>
                                <option value="credit">رصيد</option>
                                <option value="exchange">استبدال</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">وصف السبب</label>
                            <textarea class="form-control" name="reason_description" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">عناصر المرتجع</label>
                        <div id="returnItems">
                            <div class="return-item row mb-2">
                                <div class="col-md-5">
                                    <select class="form-select product-select" name="items[0][product_id]" required>
                                        <option value="">اختر المنتج</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>" 
                                                    data-price="<?php echo $product['unit_price']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control quantity" 
                                           name="items[0][quantity]" placeholder="الكمية" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control unit-price" 
                                           name="items[0][unit_price]" placeholder="السعر" required min="0.01">
                                </div>
                                <div class="col-md-2">
                                    <select class="form-select" name="items[0][condition]">
                                        <option value="new">جديد</option>
                                        <option value="used">مستعمل</option>
                                        <option value="damaged">تالف</option>
                                        <option value="defective">معيب</option>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger remove-item">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addReturnItemBtn">
                            <i class="bi bi-plus-circle me-2"></i>إضافة عنصر
                        </button>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إنشاء مرتجع</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// تحديث العميل عند اختيار البيع
document.getElementById('saleSelect')?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const customerName = selectedOption.dataset.customer || '';
    document.getElementById('customerName').value = customerName;
    // يمكنك إضافة منطق لجلب customer_id من قاعدة البيانات
});

// منطق إضافة وحذف العناصر (مشابه لطلبات العملاء)
let returnItemIndex = 1;
document.getElementById('addReturnItemBtn')?.addEventListener('click', function() {
    // إضافة عنصر جديد
});
</script>
<?php endif; ?>
<?php endif; ?>
<?php if ($section === 'exchanges'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>إدارة الاستبدال</h3>
    <?php if (hasRole(['sales', 'accountant'])): ?>
    <button class="btn btn-primary" type="button" disabled title="سيتم توفير نموذج إنشاء قريباً">
        <i class="bi bi-plus-circle me-2"></i>إنشاء استبدال جديد
    </button>
    <?php endif; ?>
</div>

<?php if ($exchangeError): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($exchangeError); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($exchangeSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($exchangeSuccess); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($selectedExchange): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">استبدال رقم: <?php echo htmlspecialchars($selectedExchange['exchange_number']); ?></h5>
            <a href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'exchanges'], $exchangeQueryParams)); ?>" class="btn btn-light btn-sm">
                <i class="bi bi-x"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">العميل:</th>
                            <td><?php echo htmlspecialchars($selectedExchange['customer_name'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>رقم البيع الأصلي:</th>
                            <td><?php echo htmlspecialchars($selectedExchange['sale_number'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>تاريخ الاستبدال:</th>
                            <td><?php echo formatDate($selectedExchange['exchange_date']); ?></td>
                        </tr>
                        <tr>
                            <th>نوع الاستبدال:</th>
                            <td>
                                <?php 
                                $types = [
                                    'same_product' => 'نفس المنتج',
                                    'different_product' => 'منتج مختلف',
                                    'upgrade' => 'ترقية',
                                    'downgrade' => 'تخفيض'
                                ];
                                echo $types[$selectedExchange['exchange_type']] ?? $selectedExchange['exchange_type'];
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table dashboard-table-details">
                        <tr>
                            <th width="40%">الحالة:</th>
                            <td>
                                <span class="badge bg-<?php 
                                    echo $selectedExchange['status'] === 'completed' ? 'success' : 
                                        ($selectedExchange['status'] === 'rejected' ? 'danger' : 
                                        ($selectedExchange['status'] === 'approved' ? 'info' : 'warning')); 
                                ?>">
                                    <?php 
                                    $statuses = [
                                        'pending' => 'معلق',
                                        'approved' => 'موافق عليه',
                                        'rejected' => 'مرفوض',
                                        'completed' => 'مكتمل'
                                    ];
                                    echo $statuses[$selectedExchange['status']] ?? $selectedExchange['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th>المبلغ الأصلي:</th>
                            <td><?php echo formatCurrency($selectedExchange['original_total']); ?></td>
                        </tr>
                        <tr>
                            <th>المبلغ الجديد:</th>
                            <td><?php echo formatCurrency($selectedExchange['new_total']); ?></td>
                        </tr>
                        <tr>
                            <th>الفرق:</th>
                            <td>
                                <strong class="<?php echo $selectedExchange['difference_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo formatCurrency($selectedExchange['difference_amount']); ?>
                                </strong>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-6">
                    <h6>المنتجات المرتجعة:</h6>
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th>الكمية</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedExchange['return_items'] as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                        <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6>المنتجات الجديدة:</h6>
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle">
                            <thead>
                                <tr>
                                    <th>المنتج</th>
                                    <th>الكمية</th>
                                    <th>السعر</th>
                                    <th>الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selectedExchange['new_items'] as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                                        <td><?php echo number_format($item['quantity'], 2); ?></td>
                                        <td><?php echo formatCurrency($item['unit_price']); ?></td>
                                        <td><?php echo formatCurrency($item['total_price']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($selectedExchange['status'] === 'pending' && hasRole('manager')): ?>
                <div class="mt-3">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="section" value="exchanges">
                        <input type="hidden" name="action" value="approve_exchange">
                        <input type="hidden" name="exchange_id" value="<?php echo $selectedExchange['id']; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle me-2"></i>الموافقة على الاستبدال
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="returns">
            <input type="hidden" name="section" value="exchanges">
            <div class="col-md-3">
                <label class="form-label">العميل</label>
                <select class="form-select" name="exchange_customer_id">
                    <option value="">جميع العملاء</option>
                    <?php 
                    $selectedExchangeCustomer = intval($_GET['exchange_customer_id'] ?? 0);
                    foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $selectedExchangeCustomer === intval($customer['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="exchange_status">
                    <option value="">جميع الحالات</option>
                    <option value="pending" <?php echo ($_GET['exchange_status'] ?? '') === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo ($_GET['exchange_status'] ?? '') === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo ($_GET['exchange_status'] ?? '') === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                    <option value="completed" <?php echo ($_GET['exchange_status'] ?? '') === 'completed' ? 'selected' : ''; ?>>مكتمل</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="exchange_date_from" value="<?php echo htmlspecialchars($_GET['exchange_date_from'] ?? ''); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="exchange_date_to" value="<?php echo htmlspecialchars($_GET['exchange_date_to'] ?? ''); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة الاستبدالات (<?php echo $exchangeTotal; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>رقم الاستبدال</th>
                        <th>العميل</th>
                        <th>تاريخ الاستبدال</th>
                        <th>المبلغ الأصلي</th>
                        <th>المبلغ الجديد</th>
                        <th>الفرق</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($exchangeList)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد استبدالات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($exchangeList as $exchange): ?>
                            <tr>
                                <td>
                                    <a href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'exchanges', 'exchange_id' => $exchange['id'], 'exch_p' => $exchangePageNum], $exchangeQueryParams)); ?>" class="text-decoration-none">
                                        <strong><?php echo htmlspecialchars($exchange['exchange_number']); ?></strong>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($exchange['customer_name'] ?? '-'); ?></td>
                                <td><?php echo formatDate($exchange['exchange_date']); ?></td>
                                <td><?php echo formatCurrency($exchange['original_total']); ?></td>
                                <td><?php echo formatCurrency($exchange['new_total']); ?></td>
                                <td>
                                    <span class="<?php echo $exchange['difference_amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo formatCurrency($exchange['difference_amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $exchange['status'] === 'completed' ? 'success' : 
                                            ($exchange['status'] === 'rejected' ? 'danger' : 
                                            ($exchange['status'] === 'approved' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php 
                                        $statuses = [
                                            'pending' => 'معلق',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض',
                                            'completed' => 'مكتمل'
                                        ];
                                        echo $statuses[$exchange['status']] ?? $exchange['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'exchanges', 'exchange_id' => $exchange['id'], 'exch_p' => $exchangePageNum], $exchangeQueryParams)); ?>" 
                                       class="btn btn-info btn-sm" title="عرض">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($exchangeTotalPages > 1): ?>
        <nav aria-label="exchanges pagination" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $exchangePageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'exchanges', 'exch_p' => $exchangePageNum - 1], $exchangeQueryParams)); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>

                <?php 
                $exchangeStartPage = max(1, $exchangePageNum - 2);
                $exchangeEndPage = min($exchangeTotalPages, $exchangePageNum + 2);
                for ($i = $exchangeStartPage; $i <= $exchangeEndPage; $i++): ?>
                    <li class="page-item <?php echo $i === $exchangePageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'exchanges', 'exch_p' => $i], $exchangeQueryParams)); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <li class="page-item <?php echo $exchangePageNum >= $exchangeTotalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge(['page' => 'returns', 'section' => 'exchanges', 'exch_p' => $exchangePageNum + 1], $exchangeQueryParams)); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php if (hasRole(['sales', 'accountant'])): ?>
<div class="alert alert-info d-flex align-items-center" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    يتم العمل على واجهة إنشاء الاستبدال من خلال هذه الصفحة، يرجى التواصل مع فريق التطوير عند الحاجة.
</div>
<?php endif; ?>
<?php endif; ?>

