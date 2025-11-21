<?php
/**
 * صفحة إدارة الموردين
 * Suppliers Management Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/production_helper.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

// إنشاء/تحديث جدول suppliers لإضافة supplier_code و type
try {
    $supplierCodeCheck = $db->queryOne("SHOW COLUMNS FROM suppliers LIKE 'supplier_code'");
    if (empty($supplierCodeCheck)) {
        $db->execute("ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(20) NULL AFTER id");
        $db->execute("ALTER TABLE suppliers ADD UNIQUE KEY supplier_code (supplier_code)");
    }
    
    $supplierTypeCheck = $db->queryOne("SHOW COLUMNS FROM suppliers LIKE 'type'");
    if (empty($supplierTypeCheck)) {
        $db->execute("ALTER TABLE suppliers ADD COLUMN type ENUM('honey', 'packaging', 'nuts', 'olive_oil', 'derivatives', 'beeswax', 'sesame') NULL DEFAULT NULL AFTER supplier_code");
    } else {
        // التحقق من وجود 'sesame' في ENUM وتحديثه إذا لم يكن موجوداً
        $typeColumn = $db->queryOne("SHOW COLUMNS FROM suppliers WHERE Field = 'type'");
        if ($typeColumn) {
            $typeEnum = $typeColumn['Type'];
            if (stripos($typeEnum, 'sesame') === false) {
                try {
                    $db->execute("ALTER TABLE suppliers MODIFY COLUMN type ENUM('honey', 'packaging', 'nuts', 'olive_oil', 'derivatives', 'beeswax', 'sesame') NULL DEFAULT NULL");
                } catch (Exception $e) {
                    error_log("Error adding 'sesame' to supplier type enum: " . $e->getMessage());
                }
            }
        }
    }
    
    // توليد كود للموردين الموجودين الذين لا يملكون كود
    $suppliersWithoutCode = $db->query("SELECT id, type FROM suppliers WHERE supplier_code IS NULL OR supplier_code = ''");
    foreach ($suppliersWithoutCode as $supplier) {
        if ($supplier['type']) {
            $supplierCode = generateSupplierCode($supplier['type'], $db);
            $db->execute("UPDATE suppliers SET supplier_code = ? WHERE id = ?", [$supplierCode, $supplier['id']]);
        }
    }
} catch (Exception $e) {
    error_log("Error updating suppliers table: " . $e->getMessage());
}

$pagesPath = __DIR__ . '/../../includes/pagination.php';
if (file_exists($pagesPath)) {
    require_once $pagesPath;
}

try {
    $balanceAuditCheck = $db->queryOne("SHOW TABLES LIKE 'supplier_balance_history'");
    if (empty($balanceAuditCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `supplier_balance_history` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `supplier_id` int(11) NOT NULL,
              `change_amount` decimal(15,2) NOT NULL,
              `previous_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
              `new_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
              `type` enum('topup','payment') NOT NULL,
              `notes` text DEFAULT NULL,
              `reference_id` int(11) DEFAULT NULL COMMENT 'ID of related record (financial transaction, etc)',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id` (`supplier_id`),
              KEY `created_by` (`created_by`),
              KEY `type` (`type`),
              KEY `created_at` (`created_at`),
              CONSTRAINT `supplier_balance_history_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE,
              CONSTRAINT `supplier_balance_history_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    error_log("Error creating supplier_balance_history table: " . $e->getMessage());
}

try {
    $financialTransactionsCheck = $db->queryOne("SHOW TABLES LIKE 'financial_transactions'");
    if (empty($financialTransactionsCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `financial_transactions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `type` enum('expense','income','transfer','payment') NOT NULL,
              `amount` decimal(15,2) NOT NULL,
              `supplier_id` int(11) DEFAULT NULL,
              `description` text NOT NULL,
              `reference_number` varchar(50) DEFAULT NULL,
              `status` enum('pending','approved','rejected') DEFAULT 'pending',
              `approved_by` int(11) DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `approved_at` timestamp NULL DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `supplier_id` (`supplier_id`),
              KEY `created_by` (`created_by`),
              KEY `approved_by` (`approved_by`),
              KEY `status` (`status`),
              KEY `created_at` (`created_at`),
              CONSTRAINT `financial_transactions_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
              CONSTRAINT `financial_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `financial_transactions_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
} catch (Exception $e) {
    error_log("Error creating financial_transactions table: " . $e->getMessage());
}

/**
 * دالة لتوليد كود مورد فريد بناءً على نوع المورد
 */
function generateSupplierCode($type, $db) {
    // رموز أنواع الموردين
    $typeCodes = [
        'honey' => 'HNY',       // مورد عسل
        'packaging' => 'PKG',   // أدوات تعبئة
        'nuts' => 'NUT',        // مكسرات
        'olive_oil' => 'OIL',   // زيت زيتون
        'derivatives' => 'DRV', // مشتقات
        'beeswax' => 'WAX',     // شمع عسل
        'sesame' => 'SES'       // مورد سمسم
    ];
    
    $prefix = $typeCodes[$type] ?? 'SUP';
    
    // البحث عن آخر رقم تسلسلي لهذا النوع
    $lastCode = $db->queryOne(
        "SELECT supplier_code FROM suppliers 
         WHERE type = ? AND supplier_code LIKE ? 
         ORDER BY supplier_code DESC 
         LIMIT 1",
        [$type, $prefix . '%']
    );
    
    $sequence = 1;
    if ($lastCode) {
        // استخراج الرقم التسلسلي من الكود
        $lastSequence = intval(substr($lastCode['supplier_code'], strlen($prefix)));
        $sequence = $lastSequence + 1;
    }
    
    // كود بثلاثة أرقام (001, 002, ...)
    $code = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    
    // التأكد من عدم التكرار
    $counter = 0;
    while ($db->queryOne("SELECT id FROM suppliers WHERE supplier_code = ?", [$code])) {
        $counter++;
        $sequence++;
        $code = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
        
        if ($counter > 999) {
            // إذا فشل 999 مرة، أضف رقم عشوائي
            $code = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
            break;
        }
    }
    
    return $code;
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if (!empty($statusFilter)) {
    $whereConditions[] = "status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$totalCountQuery = "SELECT COUNT(*) as total FROM suppliers $whereClause";
$totalCount = $db->queryOne($totalCountQuery, $params)['total'] ?? 0;
$totalPages = ceil($totalCount / $perPage);

// Get suppliers
$suppliersQuery = "SELECT * FROM suppliers $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$queryParams = array_merge($params, [$perPage, $offset]);
$suppliers = $db->query($suppliersQuery, $queryParams);

$supplierSupplyLogs = [];
$supplierSupplyTotals = [];
$supplyTodayDate = date('Y-m-d');
$supplyMonthStart = date('Y-m-01');
$supplyMonthEnd = date('Y-m-t');
if (strtotime($supplyMonthEnd) > strtotime($supplyTodayDate)) {
    $supplyMonthEnd = $supplyTodayDate;
}
$supplierSupplyRangeStartLabel = function_exists('formatDate') ? formatDate($supplyMonthStart) : $supplyMonthStart;
$supplierSupplyRangeEndLabel = function_exists('formatDate') ? formatDate($supplyMonthEnd) : $supplyMonthEnd;

if (!empty($suppliers)) {
    $supplierIds = array_values(array_filter(array_map(static function ($supplierItem) {
        return isset($supplierItem['id']) ? (int)$supplierItem['id'] : 0;
    }, $suppliers), static function ($id) {
        return $id > 0;
    }));

    if (!empty($supplierIds) && ensureProductionSupplyLogsTable()) {
        $placeholders = implode(',', array_fill(0, count($supplierIds), '?'));
        $supplyParams = $supplierIds;
        $supplyParams[] = $supplyMonthStart . ' 00:00:00';
        $supplyParams[] = $supplyMonthEnd . ' 23:59:59';

        try {
            $supplyLogs = $db->query(
                "
                SELECT id, supplier_id, supplier_name, material_category, material_label, quantity, unit, details, recorded_at
                FROM production_supply_logs
                WHERE supplier_id IN ($placeholders)
                  AND recorded_at BETWEEN ? AND ?
                ORDER BY recorded_at DESC, id DESC
                ",
                $supplyParams
            );
        } catch (Exception $e) {
            error_log('Suppliers page: failed to load supply logs -> ' . $e->getMessage());
            $supplyLogs = [];
        }

        foreach ($supplyLogs as $log) {
            $logSupplierId = isset($log['supplier_id']) ? (int)$log['supplier_id'] : 0;
            if ($logSupplierId <= 0) {
                continue;
            }

            if (!isset($supplierSupplyLogs[$logSupplierId])) {
                $supplierSupplyLogs[$logSupplierId] = [];
                $supplierSupplyTotals[$logSupplierId] = 0.0;
            }

            $supplierSupplyLogs[$logSupplierId][] = $log;
            $supplierSupplyTotals[$logSupplierId] += isset($log['quantity']) ? (float)$log['quantity'] : 0.0;
        }
    }
}

$supplierSupplyCategoryLabels = [
    'honey' => 'العسل',
    'olive_oil' => 'زيت الزيتون',
    'beeswax' => 'شمع العسل',
    'derivatives' => 'المشتقات',
    'nuts' => 'المكسرات',
    'packaging' => 'أدوات التعبئة',
    'raw' => 'المواد الخام',
    'other' => 'مواد أخرى',
];

$historyPage = isset($_GET['history_page']) ? max(1, intval($_GET['history_page'])) : 1;
$historyLimit = 10;
$historyOffset = ($historyPage - 1) * $historyLimit;

$historyWhereClause = '';
$historyParams = [];
if (!empty($search)) {
    $historyWhereClause = "WHERE s.name LIKE ?";
    $historyParams[] = "%$search%";
}

$historyQuery = "
    SELECT h.*, s.name AS supplier_name, u.full_name AS user_name
    FROM supplier_balance_history h
    LEFT JOIN suppliers s ON h.supplier_id = s.id
    LEFT JOIN users u ON h.created_by = u.id
    $historyWhereClause
    ORDER BY h.created_at DESC
    LIMIT ? OFFSET ?
";
$historyParams[] = $historyLimit;
$historyParams[] = $historyOffset;
$balanceHistory = $db->query($historyQuery, $historyParams);

$historyCountQuery = "
    SELECT COUNT(*) as total
    FROM supplier_balance_history h
    LEFT JOIN suppliers s ON h.supplier_id = s.id
    $historyWhereClause
";
$historyTotal = $db->queryOne($historyCountQuery, array_slice($historyParams, 0, -2))['total'] ?? 0;
$historyTotalPages = ceil($historyTotal / $historyLimit);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? null;
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            $error = 'اسم المورد مطلوب';
        } elseif (empty($type)) {
            $error = 'نوع المورد مطلوب';
        } else {
            try {
                $supplierCode = generateSupplierCode($type, $db);
                
                $db->execute(
                    "INSERT INTO suppliers (supplier_code, type, name, contact_person, phone, email, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$supplierCode, $type, $name, $contact_person ?: null, $phone ?: null, $email ?: null, $address ?: null, $status]
                );
                // تطبيق PRG pattern لمنع التكرار
                $successMessage = 'تم إضافة المورد بنجاح - كود المورد: ' . $supplierCode;
                preventDuplicateSubmission($successMessage, ['page' => 'suppliers'], null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? null;
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            $error = 'اسم المورد مطلوب';
        } elseif (empty($type)) {
            $error = 'نوع المورد مطلوب';
        } else {
            try {
                $currentSupplier = $db->queryOne("SELECT type, supplier_code FROM suppliers WHERE id = ?", [$id]);
                
                $supplierCode = $currentSupplier['supplier_code'] ?? null;
                if ($currentSupplier && $currentSupplier['type'] !== $type) {
                    $supplierCode = generateSupplierCode($type, $db);
                }
                
                $db->execute(
                    "UPDATE suppliers SET supplier_code = ?, type = ?, name = ?, contact_person = ?, phone = ?, email = ?, address = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$supplierCode, $type, $name, $contact_person ?: null, $phone ?: null, $email ?: null, $address ?: null, $status, $id]
                );
                // تطبيق PRG pattern لمنع التكرار
                $successMessage = 'تم تحديث المورد بنجاح';
                preventDuplicateSubmission($successMessage, ['page' => 'suppliers'], null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'add_balance' || $action === 'record_payment') {
        $supplierId = intval($_POST['supplier_id'] ?? 0);
        $amount = cleanFinancialValue($_POST['amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($supplierId <= 0) {
            $error = 'المورد غير محدد.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ صالح.';
        } else {
            $supplier = $db->queryOne("SELECT id, name, balance FROM suppliers WHERE id = ?", [$supplierId]);
            if (!$supplier) {
                $error = 'لم يتم العثور على المورد.';
            } else {
                $previousBalance = cleanFinancialValue($supplier['balance'] ?? 0);
                $changeAmount = $amount;
                $newBalance = $previousBalance;
                $historyType = $action === 'add_balance' ? 'topup' : 'payment';
                $transactionType = $action === 'add_balance' ? 'expense' : 'payment';
                $description = $action === 'add_balance'
                    ? 'إضافة رصيد للمورد: ' . $supplier['name']
                    : 'تسجيل سداد للمورد: ' . $supplier['name'];
                
                if ($action === 'add_balance') {
                    $newBalance = $previousBalance + $changeAmount;
                } else {
                    if ($amount > $previousBalance) {
                        $error = 'قيمة السداد تتجاوز الرصيد الحالي للمورد.';
                    } else {
                        $newBalance = $previousBalance - $changeAmount;
                        $changeAmount = -$changeAmount;
                    }
                }
                
                if (empty($error)) {
                    try {
                        $db->beginTransaction();
                        
                        $db->execute(
                            "UPDATE suppliers SET balance = ?, updated_at = NOW() WHERE id = ?",
                            [$newBalance, $supplierId]
                        );
                        
                        $db->execute(
                            "INSERT INTO supplier_balance_history (supplier_id, change_amount, previous_balance, new_balance, type, notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?)",
                            [
                                $supplierId,
                                $changeAmount,
                                $previousBalance,
                                $newBalance,
                                $historyType,
                                $notes ?: null,
                                $currentUser['id']
                            ]
                        );
                        $historyId = $db->lastInsertId();
                        
                        $db->execute(
                            "INSERT INTO financial_transactions (type, amount, supplier_id, description, reference_number, status, approved_by, created_by, approved_at)
                             VALUES (?, ?, ?, ?, ?, 'approved', ?, ?, NOW())",
                            [
                                $transactionType,
                                abs($changeAmount),
                                $supplierId,
                                $description . ($notes ? ' - ملاحظات: ' . $notes : ''),
                                'SUP-' . $supplierId . '-' . date('YmdHis'),
                                $currentUser['id'],
                                $currentUser['id']
                            ]
                        );
                        $transactionId = $db->lastInsertId();
                        
                        $db->execute(
                            "UPDATE supplier_balance_history SET reference_id = ? WHERE id = ?",
                            [$transactionId, $historyId]
                        );
                        
                        logAudit(
                            $currentUser['id'],
                            $action === 'add_balance' ? 'supplier_balance_topup' : 'supplier_payment',
                            'supplier',
                            $supplierId,
                            ['balance' => $previousBalance],
                            ['amount' => $amount, 'new_balance' => $newBalance, 'notes' => $notes]
                        );
                        
                        $db->commit();
                        
                        $success = $action === 'add_balance'
                            ? 'تم إضافة الرصيد للمورد بنجاح.'
                            : 'تم تسجيل السداد للمورد بنجاح.';
                        $redirectUrl = '?page=suppliers&success=' . urlencode($success);
                        if (!headers_sent()) {
                            header('Location: ' . $redirectUrl);
                            exit;
                        } else {
                            echo '<script>window.location.href = "' . $redirectUrl . '";</script>';
                            exit;
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        error_log("Supplier balance update error: " . $e->getMessage());
                        $error = 'حدث خطأ أثناء تحديث رصيد المورد. يرجى المحاولة مرة أخرى.';
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $db->execute("DELETE FROM suppliers WHERE id = ?", [$id]);
                $success = 'تم حذف المورد بنجاح';
                if (!headers_sent()) {
                    header('Location: ?page=suppliers&success=' . urlencode($success));
                    exit;
                } else {
                    echo '<script>window.location.href = "?page=suppliers&success=' . urlencode($success) . '";</script>';
                    exit;
                }
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    }
}

// Get success message from URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Get supplier for editing
$editSupplier = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editSupplier = $db->queryOne("SELECT * FROM suppliers WHERE id = ?", [$editId]);
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-truck me-2"></i><?php echo (isset($lang) && isset($lang['suppliers'])) ? $lang['suppliers'] : 'الموردين'; ?> (<?php echo $totalCount; ?>)</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
            <i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?>
        </button>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
                <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Search and Filter -->
        <?php
        require_once __DIR__ . '/../../includes/path_helper.php';
        $currentUrl = getRelativeUrl('dashboard/accountant.php');
        ?>
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="mb-4">
            <input type="hidden" name="page" value="suppliers">
            <div class="row g-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" name="search" placeholder="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث...'; ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <select class="form-select" name="status">
                        <option value=""><?php echo isset($lang['all']) ? $lang['all'] : 'جميع الحالات'; ?></option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>><?php echo isset($lang['active']) ? $lang['active'] : 'نشط'; ?></option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo isset($lang['inactive']) ? $lang['inactive'] : 'غير نشط'; ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline w-100">
                        <i class="bi bi-search me-2"></i><?php echo isset($lang['filter']) ? $lang['filter'] : 'تصفية'; ?>
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Suppliers Table -->
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>كود المورد</th>
                        <th><?php echo isset($lang['supplier_name']) ? $lang['supplier_name'] : 'اسم المورد'; ?></th>
                        <th>نوع المورد</th>
                        <th><?php echo isset($lang['contact_person']) ? $lang['contact_person'] : 'جهة الاتصال'; ?></th>
                        <th><?php echo isset($lang['phone']) ? $lang['phone'] : 'الهاتف'; ?></th>
                        <th><?php echo isset($lang['email']) ? $lang['email'] : 'البريد'; ?></th>
                        <th><?php echo isset($lang['balance']) ? $lang['balance'] : 'الرصيد'; ?></th>
                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                        <th><?php echo isset($lang['actions']) ? $lang['actions'] : 'الإجراءات'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                <i class="bi bi-inbox me-2"></i><?php echo isset($lang['no_data']) ? $lang['no_data'] : 'لا توجد بيانات'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $typeLabels = [
                            'honey' => 'مورد عسل',
                            'packaging' => 'أدوات تعبئة',
                            'nuts' => 'مكسرات',
                            'olive_oil' => 'زيت زيتون',
                            'derivatives' => 'مشتقات',
                            'beeswax' => 'شمع عسل'
                        ];
                        foreach ($suppliers as $index => $supplier):
                            $supplierId = isset($supplier['id']) ? (int)$supplier['id'] : 0;
                            $supplierSupplyEntries = $supplierSupplyLogs[$supplierId] ?? [];
                            $supplierSupplyTotal = $supplierSupplyTotals[$supplierId] ?? 0.0;
                            $supplierSupplyCount = count($supplierSupplyEntries);
                        ?>
                            <tr>
                                <td data-label="#"><?php echo $offset + $index + 1; ?></td>
                                <td data-label="كود المورد">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($supplier['supplier_code'] ?? '-'); ?></span>
                                </td>
                                <td data-label="الاسم"><strong><?php echo htmlspecialchars($supplier['name']); ?></strong></td>
                                <td data-label="نوع المورد">
                                    <span class="badge bg-info"><?php echo htmlspecialchars($typeLabels[$supplier['type'] ?? ''] ?? '-'); ?></span>
                                </td>
                                <td data-label="جهة الاتصال"><?php echo htmlspecialchars($supplier['contact_person'] ?? '-'); ?></td>
                                <td data-label="الهاتف"><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                                <td data-label="البريد"><?php echo htmlspecialchars($supplier['email'] ?? '-'); ?></td>
                                <td data-label="الرصيد">
                                    <?php 
                                    // تنظيف شامل للرصيد قبل العرض باستخدام دالة cleanFinancialValue
                                    $balance = cleanFinancialValue($supplier['balance'] ?? 0);
                                    ?>
                                    <span class="badge <?php echo $balance >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo formatCurrency($balance); ?>
                                    </span>
                                </td>
                                <td data-label="الحالة">
                                    <span class="badge bg-<?php echo $supplier['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo isset($lang[$supplier['status']]) ? $lang[$supplier['status']] : $supplier['status']; ?>
                                    </span>
                                </td>
                                <td data-label="الإجراءات">
                                    <div class="btn-group btn-group-sm flex-wrap">
                                        <button type="button"
                                                class="btn btn-success mb-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#addSupplierBalanceModal"
                                                data-supplier-id="<?php echo $supplierId; ?>"
                                                data-supplier-name="<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-supplier-balance="<?php echo $balance; ?>">
                                            <i class="bi bi-plus-circle"></i>
                                            <span class="d-none d-lg-inline">إضافة رصيد</span>
                                        </button>
                                        <button type="button"
                                                class="btn btn-warning mb-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#supplierPaymentModal"
                                                data-supplier-id="<?php echo $supplierId; ?>"
                                                data-supplier-name="<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-supplier-balance="<?php echo $balance; ?>">
                                            <i class="bi bi-cash-coin"></i>
                                            <span class="d-none d-lg-inline">تسجيل سداد</span>
                                        </button>
                                        <button type="button"
                                                class="btn btn-info text-white mb-1"
                                                data-bs-toggle="collapse"
                                                data-bs-target="#supplierSupply-<?php echo $supplierId; ?>"
                                                aria-expanded="false"
                                                aria-controls="supplierSupply-<?php echo $supplierId; ?>">
                                            <i class="bi bi-truck"></i>
                                            <span class="d-none d-lg-inline">توريدات الشهر</span>
                                        </button>
                                        <a href="?page=suppliers&edit=<?php echo $supplierId; ?>" class="btn btn-outline mb-1" data-bs-toggle="tooltip" title="<?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?>">
                                            <i class="bi bi-pencil"></i>
                                            <span class="d-none d-md-inline"><?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?></span>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger mb-1" onclick="deleteSupplier(<?php echo $supplierId; ?>, '<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES); ?>')" data-bs-toggle="tooltip" title="<?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?>">
                                            <i class="bi bi-trash"></i>
                                            <span class="d-none d-md-inline"><?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr class="supplier-supply-row">
                                <td colspan="10" class="p-0 border-top-0">
                                    <div class="collapse" id="supplierSupply-<?php echo $supplierId; ?>">
                                        <div class="bg-light border-top px-3 py-3">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                                                <div>
                                                    <span class="fw-semibold"><i class="bi bi-truck me-2"></i>توريدات المورد خلال الشهر الحالي</span>
                                                    <div class="text-muted small">
                                                        الفترة: <?php echo htmlspecialchars($supplierSupplyRangeStartLabel); ?> - <?php echo htmlspecialchars($supplierSupplyRangeEndLabel); ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <span class="badge bg-primary text-white">
                                                        إجمالي الكمية: <?php echo number_format($supplierSupplyTotal, 3); ?>
                                                    </span>
                                                    <?php if ($supplierSupplyCount > 0): ?>
                                                        <span class="badge bg-secondary text-white">
                                                            عدد السجلات: <?php echo $supplierSupplyCount; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($supplierSupplyCount === 0): ?>
                                                <div class="alert alert-light border text-muted mb-0">
                                                    <i class="bi bi-inbox me-2"></i>لا توجد توريدات مسجلة لهذا المورد خلال الشهر الحالي.
                                                </div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-hover mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>التاريخ</th>
                                                                <th>القسم</th>
                                                                <th>المادة</th>
                                                                <th>الكمية</th>
                                                                <th>الوصف</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($supplierSupplyEntries as $supplyEntry): ?>
                                                                <?php
                                                                    $recordedAt = $supplyEntry['recorded_at'] ?? null;
                                                                    $entryDate = '—';
                                                                    $entryTime = '';
                                                                    if ($recordedAt) {
                                                                        $timestamp = strtotime($recordedAt);
                                                                        if ($timestamp) {
                                                                            $entryDate = function_exists('formatDate')
                                                                                ? formatDate($recordedAt)
                                                                                : date('Y-m-d', $timestamp);
                                                                            $entryTime = function_exists('formatTime')
                                                                                ? formatTime($recordedAt)
                                                                                : date('H:i', $timestamp);
                                                                        }
                                                                    }
                                                                    $categoryKey = $supplyEntry['material_category'] ?? '';
                                                                    $categoryLabel = $supplierSupplyCategoryLabels[$categoryKey] ?? ($categoryKey !== '' ? $categoryKey : '—');
                                                                    $materialLabel = trim((string)($supplyEntry['material_label'] ?? ''));
                                                                    $details = trim((string)($supplyEntry['details'] ?? ''));
                                                                    $quantityValue = isset($supplyEntry['quantity']) ? (float)$supplyEntry['quantity'] : 0.0;
                                                                    $unitLabel = isset($supplyEntry['unit']) && $supplyEntry['unit'] !== '' ? $supplyEntry['unit'] : 'كجم';
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <div class="fw-semibold"><?php echo htmlspecialchars($entryDate); ?></div>
                                                                        <?php if ($entryTime !== ''): ?>
                                                                            <div class="text-muted small"><?php echo htmlspecialchars($entryTime); ?></div>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                    <td><?php echo htmlspecialchars($categoryLabel); ?></td>
                                                                    <td><?php echo htmlspecialchars($materialLabel !== '' ? $materialLabel : '-'); ?></td>
                                                                    <td>
                                                                        <span class="fw-semibold text-primary"><?php echo number_format($quantityValue, 3); ?></span>
                                                                        <span class="text-muted small"><?php echo htmlspecialchars($unitLabel); ?></span>
                                                                    </td>
                                                                    <td>
                                                                        <?php if ($details !== ''): ?>
                                                                            <?php echo htmlspecialchars($details); ?>
                                                                        <?php else: ?>
                                                                            <span class="text-muted">-</span>
                                                                        <?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mt-3 small text-muted">
                                                لعرض جميع التوريدات أو تنزيل التقارير التفصيلية، تفضل بزيارة صفحة تقارير الإنتاج.
                                            </div>
                                        </div>
                                    </div>
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
                    <a class="page-link" href="?page=suppliers&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=suppliers&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=suppliers&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=suppliers&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=suppliers&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php
$historyTypeLabels = [
    'topup' => 'إضافة رصيد',
    'payment' => 'تسجيل سداد'
];
?>

<div class="card shadow-sm mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>سجل الحركات المالية للموردين</h5>
        <span class="badge bg-light text-dark">آخر <?php echo $historyLimit; ?> سجلات</span>
    </div>
    <div class="card-body">
        <?php if (empty($balanceHistory)): ?>
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox me-2"></i>لا توجد حركات مالية مسجلة بعد.
            </div>
        <?php else: ?>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>التاريخ</th>
                            <th>المورد</th>
                            <th>نوع العملية</th>
                            <th>المبلغ</th>
                            <th>الرصيد قبل / بعد</th>
                            <th>ملاحظات</th>
                            <th>تسجيل بواسطة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($balanceHistory as $index => $item): ?>
                            <?php
                                $changeAmount = cleanFinancialValue($item['change_amount']);
                                $previousBalance = cleanFinancialValue($item['previous_balance']);
                                $newBalance = cleanFinancialValue($item['new_balance']);
                                $typeLabel = $historyTypeLabels[$item['type']] ?? $item['type'];
                                $isPositive = $changeAmount >= 0;
                            ?>
                            <tr>
                                <td data-label="#"><?php echo $historyOffset + $index + 1; ?></td>
                                <td data-label="التاريخ"><?php echo date('Y-m-d H:i', strtotime($item['created_at'])); ?></td>
                                <td data-label="المورد"><strong><?php echo htmlspecialchars($item['supplier_name'] ?? 'غير معروف'); ?></strong></td>
                                <td data-label="نوع العملية">
                                    <span class="badge bg-<?php echo $isPositive ? 'success' : 'warning'; ?>">
                                        <i class="bi bi-<?php echo $isPositive ? 'arrow-up-circle' : 'arrow-down-circle'; ?> me-1"></i><?php echo $typeLabel; ?>
                                    </span>
                                </td>
                                <td data-label="المبلغ">
                                    <span class="text-<?php echo $isPositive ? 'success' : 'danger'; ?> fw-bold">
                                        <?php echo ($isPositive ? '+' : '-') . formatCurrency(abs($changeAmount)); ?>
                                    </span>
                                </td>
                                <td data-label="الرصيد قبل / بعد">
                                    <div class="small text-muted">قبل: <?php echo formatCurrency($previousBalance); ?></div>
                                    <div class="fw-semibold">بعد: <?php echo formatCurrency($newBalance); ?></div>
                                </td>
                                <td data-label="ملاحظات"><?php echo htmlspecialchars($item['notes'] ?? '—'); ?></td>
                                <td data-label="تسجيل بواسطة"><?php echo htmlspecialchars($item['user_name'] ?? 'غير معروف'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($historyTotalPages > 1): ?>
            <nav aria-label="Supplier history pagination" class="mt-3">
                <ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?php echo $historyPage <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=suppliers&history_page=<?php echo $historyPage - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                    <?php
                    $historyStart = max(1, $historyPage - 2);
                    $historyEnd = min($historyTotalPages, $historyPage + 2);
                    if ($historyStart > 1): ?>
                        <li class="page-item"><a class="page-link" href="?page=suppliers&history_page=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">1</a></li>
                        <?php if ($historyStart > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php for ($i = $historyStart; $i <= $historyEnd; $i++): ?>
                        <li class="page-item <?php echo $i == $historyPage ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=suppliers&history_page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($historyEnd < $historyTotalPages): ?>
                        <?php if ($historyEnd < $historyTotalPages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="?page=suppliers&history_page=<?php echo $historyTotalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>"><?php echo $historyTotalPages; ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?php echo $historyPage >= $historyTotalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=suppliers&history_page=<?php echo $historyPage + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?> <?php echo (isset($lang) && isset($lang['suppliers'])) ? $lang['suppliers'] : 'مورد'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['supplier_name']) ? $lang['supplier_name'] : 'اسم المورد'; ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع المورد <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" required id="supplier_type">
                            <option value="">اختر نوع المورد</option>
                            <option value="honey">مورد عسل</option>
                            <option value="packaging">أدوات تعبئة</option>
                            <option value="nuts">مكسرات</option>
                            <option value="sesame">مورد سمسم</option>
                            <option value="olive_oil">زيت زيتون</option>
                            <option value="derivatives">مشتقات</option>
                            <option value="beeswax">شمع عسل</option>
                        </select>
                        <small class="text-muted">سيتم توليد كود المورد تلقائياً بناءً على النوع المختار</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['contact_person']) ? $lang['contact_person'] : 'جهة الاتصال'; ?></label>
                        <input type="text" class="form-control" name="contact_person">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['phone']) ? $lang['phone'] : 'الهاتف'; ?></label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['email']) ? $lang['email'] : 'البريد الإلكتروني'; ?></label>
                            <input type="email" class="form-control" name="email">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['address']) ? $lang['address'] : 'العنوان'; ?></label>
                        <textarea class="form-control" name="address" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                        <select class="form-select" name="status">
                            <option value="active"><?php echo isset($lang['active']) ? $lang['active'] : 'نشط'; ?></option>
                            <option value="inactive"><?php echo isset($lang['inactive']) ? $lang['inactive'] : 'غير نشط'; ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<?php if ($editSupplier): ?>
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $editSupplier['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?> <?php echo (isset($lang) && isset($lang['suppliers'])) ? $lang['suppliers'] : 'مورد'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">كود المورد</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($editSupplier['supplier_code'] ?? '-'); ?>" readonly>
                        <small class="text-muted">سيتم تحديث الكود تلقائياً إذا تم تغيير نوع المورد</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['supplier_name']) ? $lang['supplier_name'] : 'اسم المورد'; ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($editSupplier['name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع المورد <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" required id="edit_supplier_type">
                            <option value="">اختر نوع المورد</option>
                            <option value="honey" <?php echo ($editSupplier['type'] ?? '') === 'honey' ? 'selected' : ''; ?>>مورد عسل</option>
                            <option value="packaging" <?php echo ($editSupplier['type'] ?? '') === 'packaging' ? 'selected' : ''; ?>>أدوات تعبئة</option>
                            <option value="nuts" <?php echo ($editSupplier['type'] ?? '') === 'nuts' ? 'selected' : ''; ?>>مكسرات</option>
                            <option value="olive_oil" <?php echo ($editSupplier['type'] ?? '') === 'olive_oil' ? 'selected' : ''; ?>>زيت زيتون</option>
                            <option value="derivatives" <?php echo ($editSupplier['type'] ?? '') === 'derivatives' ? 'selected' : ''; ?>>مشتقات</option>
                            <option value="beeswax" <?php echo ($editSupplier['type'] ?? '') === 'beeswax' ? 'selected' : ''; ?>>شمع عسل</option>
                            <option value="sesame" <?php echo ($editSupplier['type'] ?? '') === 'sesame' ? 'selected' : ''; ?>>مورد سمسم</option>
                        </select>
                        <small class="text-muted">سيتم توليد كود جديد إذا تم تغيير النوع</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['contact_person']) ? $lang['contact_person'] : 'جهة الاتصال'; ?></label>
                        <input type="text" class="form-control" name="contact_person" value="<?php echo htmlspecialchars($editSupplier['contact_person'] ?? ''); ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['phone']) ? $lang['phone'] : 'الهاتف'; ?></label>
                            <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($editSupplier['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['email']) ? $lang['email'] : 'البريد الإلكتروني'; ?></label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($editSupplier['email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['address']) ? $lang['address'] : 'العنوان'; ?></label>
                        <textarea class="form-control" name="address" rows="3"><?php echo htmlspecialchars($editSupplier['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                        <select class="form-select" name="status">
                            <option value="active" <?php echo $editSupplier['status'] === 'active' ? 'selected' : ''; ?>><?php echo isset($lang['active']) ? $lang['active'] : 'نشط'; ?></option>
                            <option value="inactive" <?php echo $editSupplier['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo isset($lang['inactive']) ? $lang['inactive'] : 'غير نشط'; ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add Balance Modal -->
<div class="modal fade" id="addSupplierBalanceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_balance">
                <input type="hidden" name="supplier_id" id="balanceSupplierId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة رصيد للمورد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المورد</label>
                        <input type="text" class="form-control" id="balanceSupplierName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="text" class="form-control" id="balancePreviousValue" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">المبلغ المطلوب إضافته <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات (اختياري)</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="سبب إضافة الرصيد أو تفاصيل العملية"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">حفظ الرصيد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="supplierPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="supplier_id" id="paymentSupplierId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تسجيل سداد للمورد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المورد</label>
                        <input type="text" class="form-control" id="paymentSupplierName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الرصيد الحالي</label>
                        <input type="text" class="form-control" id="paymentCurrentBalance" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">مبلغ السداد <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required>
                        <small class="text-muted d-block mt-1">لا يمكن إدخال مبلغ أكبر من الرصيد الحالي.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ملاحظات (اختياري)</label>
                        <textarea class="form-control" name="notes" rows="3" placeholder="تفاصيل الدفع، رقم الإيصال، إلخ"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning text-white">حفظ السداد</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function deleteSupplier(id, name) {
    if (confirm('<?php echo isset($lang['confirm_delete']) ? $lang['confirm_delete'] : 'هل أنت متأكد من حذف'; ?> "' + name + '"؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize tooltips and modal helpers
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    <?php if ($editSupplier): ?>
    const editModal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
    editModal.show();
    <?php endif; ?>

    const balanceModal = document.getElementById('addSupplierBalanceModal');
    const paymentModal = document.getElementById('supplierPaymentModal');

    document.querySelectorAll('[data-bs-target="#addSupplierBalanceModal"]').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!balanceModal) {
                return;
            }
            const supplierId = this.getAttribute('data-supplier-id');
            const supplierName = this.getAttribute('data-supplier-name');
            const balance = parseFloat(this.getAttribute('data-supplier-balance') || 0);

            balanceModal.querySelector('#balanceSupplierId').value = supplierId;
            balanceModal.querySelector('#balanceSupplierName').value = supplierName;
            balanceModal.querySelector('#balancePreviousValue').value = balance.toLocaleString('ar-EG', { style: 'currency', currency: 'EGP' });
            balanceModal.querySelector('input[name="amount"]').value = '';
            balanceModal.querySelector('textarea[name="notes"]').value = '';
        });
    });

    document.querySelectorAll('[data-bs-target="#supplierPaymentModal"]').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!paymentModal) {
                return;
            }
            const supplierId = this.getAttribute('data-supplier-id');
            const supplierName = this.getAttribute('data-supplier-name');
            const balance = parseFloat(this.getAttribute('data-supplier-balance') || 0);

            paymentModal.querySelector('#paymentSupplierId').value = supplierId;
            paymentModal.querySelector('#paymentSupplierName').value = supplierName;
            paymentModal.querySelector('#paymentCurrentBalance').value = balance.toLocaleString('ar-EG', { style: 'currency', currency: 'EGP' });
            const amountInput = paymentModal.querySelector('input[name="amount"]');
            amountInput.value = '';
            amountInput.max = balance.toFixed(2);
            paymentModal.querySelector('textarea[name="notes"]').value = '';
        });
    });
});
</script>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>

