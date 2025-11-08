<?php
/**
 * صفحة إدارة الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/batch_numbers.php';
require_once __DIR__ . '/../../includes/simple_barcode.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';
require_once __DIR__ . '/../../includes/production_helper.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

/**
 * التحقق من توفر المكونات المستخدمة في صناعة المنتج
 */
function checkMaterialsAvailability($db, $templateId, $productionQuantity) {
    $missingMaterials = [];
    $insufficientMaterials = [];
    
    // 1. التحقق من مواد التعبئة
    $packagingNameExpression = getColumnSelectExpression('product_template_packaging', 'packaging_name');
    $packagingMaterials = $db->query(
        "SELECT packaging_material_id, quantity_per_unit, {$packagingNameExpression}
         FROM product_template_packaging 
         WHERE template_id = ?",
        [$templateId]
    );
    
    foreach ($packagingMaterials as $packaging) {
        $packagingId = $packaging['packaging_material_id'] ?? null;
        $requiredQuantity = floatval($packaging['quantity_per_unit']) * $productionQuantity;
        
        if ($packagingId) {
            // البحث في جدول products أولاً
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE id = ? AND status = 'active'",
                [$packagingId]
            );
            
            if ($product) {
                $availableQuantity = floatval($product['quantity'] ?? 0);
                if ($availableQuantity < $requiredQuantity) {
                    $insufficientMaterials[] = [
                        'name' => $product['name'],
                        'required' => $requiredQuantity,
                        'available' => $availableQuantity,
                        'type' => 'مواد التعبئة'
                    ];
                }
            } else {
                // البحث في جدول packaging_materials إذا كان موجوداً
                $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
                if ($packagingTableCheck) {
                    $packagingMaterial = $db->queryOne(
                        "SELECT id, name, quantity FROM packaging_materials WHERE id = ?",
                        [$packagingId]
                    );
                    if ($packagingMaterial) {
                        $availableQuantity = floatval($packagingMaterial['quantity'] ?? 0);
                        if ($availableQuantity < $requiredQuantity) {
                            $insufficientMaterials[] = [
                                'name' => $packagingMaterial['name'],
                                'required' => $requiredQuantity,
                                'available' => $availableQuantity,
                                'type' => 'مواد التعبئة'
                            ];
                        }
                    } else {
                        $missingMaterials[] = [
                            'name' => $packaging['packaging_name'] ?? 'مادة تعبئة غير معروفة',
                            'type' => 'مواد التعبئة'
                        ];
                    }
                } else {
                    $missingMaterials[] = [
                        'name' => $packaging['packaging_name'] ?? 'مادة تعبئة غير معروفة',
                        'type' => 'مواد التعبئة'
                    ];
                }
            }
        }
    }
    
    // 2. التحقق من المواد الخام
    $rawMaterials = $db->query(
        "SELECT material_name, quantity_per_unit, unit 
         FROM product_template_raw_materials 
         WHERE template_id = ?",
        [$templateId]
    );
    
    foreach ($rawMaterials as $raw) {
        $materialName = $raw['material_name'];
        $requiredQuantity = floatval($raw['quantity_per_unit']) * $productionQuantity;
        
        // البحث عن المادة في جدول products
        $product = $db->queryOne(
            "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
            [$materialName]
        );
        
        if ($product) {
            $availableQuantity = floatval($product['quantity'] ?? 0);
            if ($availableQuantity < $requiredQuantity) {
                $insufficientMaterials[] = [
                    'name' => $materialName,
                    'required' => $requiredQuantity,
                    'available' => $availableQuantity,
                    'type' => 'مواد خام',
                    'unit' => $raw['unit'] ?? 'كجم'
                ];
            }
        } else {
            $missingMaterials[] = [
                'name' => $materialName,
                'type' => 'مواد خام',
                'unit' => $raw['unit'] ?? 'كجم'
            ];
        }
    }
    
    // 3. التحقق من العسل (من القالب)
    $template = $db->queryOne("SELECT honey_quantity FROM product_templates WHERE id = ?", [$templateId]);
    $honeyQuantity = floatval($template['honey_quantity'] ?? 0);
    if ($honeyQuantity > 0) {
        $requiredHoney = $honeyQuantity * $productionQuantity;
        
        // البحث عن العسل في جدول honey_stock (المصفى فقط)
        $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
        if ($honeyStockTableCheck) {
            $honeyStock = $db->query(
                "SELECT hs.*, s.name as supplier_name 
                 FROM honey_stock hs
                 LEFT JOIN suppliers s ON hs.supplier_id = s.id
                 WHERE hs.filtered_honey_quantity > 0
                 ORDER BY hs.filtered_honey_quantity DESC"
            );
            
            $totalHoneyAvailable = 0;
            $honeyDetails = [];
            foreach ($honeyStock as $honey) {
                $available = floatval($honey['filtered_honey_quantity'] ?? 0);
                $totalHoneyAvailable += $available;
                if ($available > 0) {
                    $honeyDetails[] = $honey['honey_variety'] . ' (' . $honey['supplier_name'] . '): ' . number_format($available, 2) . ' كجم';
                }
            }
            
            if ($totalHoneyAvailable < $requiredHoney) {
                $insufficientMaterials[] = [
                    'name' => 'عسل مصفى',
                    'required' => $requiredHoney,
                    'available' => $totalHoneyAvailable,
                    'type' => 'عسل',
                    'unit' => 'كجم',
                    'details' => implode(' | ', $honeyDetails)
                ];
            }
        } else {
            // البحث في جدول products كبديل
            $honeyProducts = $db->query(
                "SELECT id, name, quantity FROM products 
                 WHERE (name LIKE '%عسل%' OR category = 'honey' OR category = 'raw_material') 
                 AND status = 'active'
                 ORDER BY quantity DESC"
            );
            
            $totalHoneyAvailable = 0;
            foreach ($honeyProducts as $honey) {
                $totalHoneyAvailable += floatval($honey['quantity'] ?? 0);
            }
            
            if ($totalHoneyAvailable < $requiredHoney) {
                $insufficientMaterials[] = [
                    'name' => 'عسل',
                    'required' => $requiredHoney,
                    'available' => $totalHoneyAvailable,
                    'type' => 'عسل',
                    'unit' => 'كجم'
                ];
            }
        }
    }
    
    // بناء رسالة الخطأ
    $errorMessages = [];
    
    if (!empty($missingMaterials)) {
        $missingNames = array_map(function($m) {
            return $m['name'] . ' (' . $m['type'] . ')';
        }, $missingMaterials);
        $errorMessages[] = 'مواد غير موجودة في المخزون: ' . implode(', ', $missingNames);
    }
    
    if (!empty($insufficientMaterials)) {
        $insufficientDetails = [];
        foreach ($insufficientMaterials as $mat) {
            $unit = $mat['unit'] ?? '';
            $insufficientDetails[] = sprintf(
                '%s (%s): مطلوب %s %s، متوفر %s %s',
                $mat['name'],
                $mat['type'],
                number_format($mat['required'], 2),
                $unit,
                number_format($mat['available'], 2),
                $unit
            );
        }
        $errorMessages[] = 'مواد غير كافية: ' . implode(' | ', $insufficientDetails);
    }
    
    if (!empty($errorMessages)) {
        return [
            'available' => false,
            'message' => implode(' | ', $errorMessages),
            'missing' => $missingMaterials,
            'insufficient' => $insufficientMaterials
        ];
    }
    
    return [
        'available' => true,
        'message' => 'جميع المكونات متوفرة'
    ];
}

// إنشاء جدول batch_numbers إذا لم يكن موجوداً
try {
    $batchTableCheck = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
    if (empty($batchTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `batch_numbers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `batch_number` varchar(100) NOT NULL,
              `product_id` int(11) NOT NULL,
              `production_id` int(11) DEFAULT NULL,
              `production_date` date NOT NULL,
              `honey_supplier_id` int(11) DEFAULT NULL,
              `packaging_materials` text DEFAULT NULL COMMENT 'JSON array of packaging material IDs',
              `packaging_supplier_id` int(11) DEFAULT NULL,
              `workers` text DEFAULT NULL COMMENT 'JSON array of worker IDs',
              `quantity` int(11) NOT NULL DEFAULT 1,
              `status` enum('in_production','completed','in_stock','sold','expired') DEFAULT 'in_production',
              `expiry_date` date DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `batch_number` (`batch_number`),
              KEY `product_id` (`product_id`),
              KEY `production_id` (`production_id`),
              KEY `production_date` (`production_date`),
              KEY `status` (`status`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إضافة حقل all_suppliers إذا لم يكن موجوداً
    $allSuppliersColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'all_suppliers'");
    if (empty($allSuppliersColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `all_suppliers` TEXT DEFAULT NULL COMMENT 'JSON array of all suppliers with their materials' 
                AFTER `packaging_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding all_suppliers column: " . $e->getMessage());
        }
    }
    
    // إضافة حقل honey_variety إذا لم يكن موجوداً
    $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'honey_variety'");
    if (empty($honeyVarietyColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'نوع العسل المستخدم' 
                AFTER `honey_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding honey_variety column: " . $e->getMessage());
        }
    }
} catch (Exception $e) {
    error_log("Batch numbers table creation error: " . $e->getMessage());
}

// الحصول على رسالة النجاح من session (بعد redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

// التحقق من وجود عمود date أو production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

// التحقق من وجود عمود user_id أو worker_id
$userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
$workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
$hasUserIdColumn = !empty($userIdColumnCheck);
$hasWorkerIdColumn = !empty($workerIdColumnCheck);
$userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // منع تكرار الإرسال باستخدام CSRF Token
    $submitToken = $_POST['submit_token'] ?? '';
    $sessionToken = $_SESSION['last_submit_token'] ?? '';
    
    if ($submitToken && $submitToken === $sessionToken) {
        // تم إرسال هذا النموذج من قبل - تجاهله
        $error = 'تم معالجة هذا الطلب من قبل. يرجى عدم إعادة تحميل الصفحة بعد الإرسال.';
        error_log("Duplicate form submission detected: token={$submitToken}, action={$action}");
    } elseif ($action === 'add_production') {
        // حفظ التوكن لمنع التكرار
        $_SESSION['last_submit_token'] = $submitToken;
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = $_POST['unit'] ?? 'kg'; // كجم أو جرام
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        
        // تحويل الجرام إلى كيلوجرام إذا لزم الأمر
        if ($unit === 'g' || $unit === 'gram') {
            $quantity = $quantity / 1000; // تحويل من جرام إلى كجم
        }
        
        // تحديد user_id - إذا كان المستخدم عامل إنتاج، استخدم id الخاص به، وإلا استخدم المحدد
        $selectedUserId = intval($_POST['user_id'] ?? 0);
        if ($currentUser['role'] === 'production' && $selectedUserId <= 0) {
            $selectedUserId = $currentUser['id'];
        } elseif ($selectedUserId <= 0) {
            $error = 'يجب اختيار العامل';
        }
        
        if (empty($productId) || $productId <= 0) {
            $error = 'يجب اختيار المنتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة';
        } elseif ($selectedUserId <= 0) {
            $error = 'يجب اختيار العامل';
        } else {
            // بناء الاستعلام بشكل ديناميكي
            $columns = ['product_id', 'quantity'];
            $values = [$productId, $quantity];
            $placeholders = ['?', '?'];
            
            // إضافة عمود التاريخ
            $columns[] = $dateColumn;
            $values[] = $productionDate;
            $placeholders[] = '?';
            
            // إضافة عمود user_id/worker_id
            if ($userIdColumn) {
                $columns[] = $userIdColumn;
                $values[] = $selectedUserId;
                $placeholders[] = '?';
            }
            
            // إضافة مواد الإنتاج إن وجدت
            if ($materialsUsed) {
                $columns[] = 'materials_used';
                $values[] = $materialsUsed;
                $placeholders[] = '?';
            }
            
            // إضافة الملاحظات
            if ($notes) {
                $columns[] = 'notes';
                $values[] = $notes;
                $placeholders[] = '?';
            }
            
            // إضافة الحالة (افتراضياً pending)
            $columns[] = 'status';
            $values[] = 'pending';
            $placeholders[] = '?';
            
            $sql = "INSERT INTO production (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            try {
                $result = $db->execute($sql, $values);
                
                logAudit($currentUser['id'], 'add_production', 'production', $result['insert_id'], null, [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ]);
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم إضافة سجل الإنتاج بنجاح';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ في إضافة سجل الإنتاج: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_production') {
        $_SESSION['last_submit_token'] = $submitToken;
        $productionId = intval($_POST['production_id'] ?? 0);
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = $_POST['unit'] ?? 'kg'; // كجم أو جرام
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        
        // تحويل الجرام إلى كيلوجرام إذا لزم الأمر
        if ($unit === 'g' || $unit === 'gram') {
            $quantity = $quantity / 1000; // تحويل من جرام إلى كجم
        }
        
        if ($productionId <= 0) {
            $error = 'معرف الإنتاج غير صحيح';
        } elseif ($productId <= 0) {
            $error = 'يجب اختيار المنتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة';
        } else {
            // بناء الاستعلام بشكل ديناميكي
            $setParts = ['product_id = ?', 'quantity = ?'];
            $values = [$productId, $quantity];
            
            // تحديث عمود التاريخ
            $setParts[] = "$dateColumn = ?";
            $values[] = $productionDate;
            
            // تحديث مواد الإنتاج
            if ($materialsUsed !== '') {
                $setParts[] = 'materials_used = ?';
                $values[] = $materialsUsed;
            }
            
            // تحديث الملاحظات
            if ($notes !== '') {
                $setParts[] = 'notes = ?';
                $values[] = $notes;
            }
            
            // تحديث الحالة
            $setParts[] = 'status = ?';
            $values[] = $status;
            
            $values[] = $productionId;
            
            $sql = "UPDATE production SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            try {
                $db->execute($sql, $values);
                
                logAudit($currentUser['id'], 'update_production', 'production', $productionId, null, [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'status' => $status
                ]);
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم تحديث سجل الإنتاج بنجاح';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ في تحديث سجل الإنتاج: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_production') {
        $_SESSION['last_submit_token'] = $submitToken;
        $productionId = intval($_POST['production_id'] ?? 0);
        
        if ($productionId <= 0) {
            $error = 'معرف الإنتاج غير صحيح';
        } else {
            try {
                // حذف مواد الإنتاج المرتبطة أولاً
                $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);
                
                // حذف سجل الإنتاج
                $db->execute("DELETE FROM production WHERE id = ?", [$productionId]);
                
                logAudit($currentUser['id'], 'delete_production', 'production', $productionId, null, null);
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم حذف سجل الإنتاج بنجاح';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ في حذف سجل الإنتاج: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'create_from_template') {
        $_SESSION['last_submit_token'] = $submitToken;
        $templateId = intval($_POST['template_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        
        if ($templateId <= 0) {
            $error = 'يجب اختيار قالب منتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة (أكبر من 0)';
        } else {
            try {
                $db->beginTransaction();
                
                $materialSuppliersInput = $_POST['material_suppliers'] ?? [];
                $materialSuppliers = [];
                if (is_array($materialSuppliersInput)) {
                    foreach ($materialSuppliersInput as $key => $value) {
                        $materialSuppliers[$key] = intval($value);
                    }
                }

                $materialHoneyVarietiesInput = $_POST['material_honey_varieties'] ?? [];
                $materialHoneyVarieties = [];
                if (is_array($materialHoneyVarietiesInput)) {
                    foreach ($materialHoneyVarietiesInput as $key => $value) {
                        $cleanValue = trim((string)$value);
                        if ($cleanValue !== '') {
                            $cleanValue = mb_substr($cleanValue, 0, 120, 'UTF-8');
                        }
                        $materialHoneyVarieties[$key] = $cleanValue;
                    }
                }

                if (empty($materialSuppliers)) {
                    throw new Exception('يرجى اختيار المورد المناسب لكل مادة قبل إنشاء التشغيلة.');
                }

                $templateMode = $_POST['template_mode'] ?? 'advanced';
                if ($templateMode !== 'advanced') {
                    $templateMode = 'advanced';
                }
                $templateType = trim($_POST['template_type'] ?? 'legacy');

                // محاولة الحصول على القالب الموحد أولاً
                $unifiedTemplate = $db->queryOne(
                    "SELECT * FROM unified_product_templates WHERE id = ? AND status = 'active'",
                    [$templateId]
                );
                
                $isUnifiedTemplate = !empty($unifiedTemplate);
                
                if ($isUnifiedTemplate) {
                    // قالب موحد جديد
                    $template = $unifiedTemplate;
                    
                    // التحقق من توفر المواد الخام
                    $rawMaterials = $db->query(
                        "SELECT * FROM template_raw_materials WHERE template_id = ?",
                        [$templateId]
                    );
                    
                    if (empty($rawMaterials)) {
                        throw new Exception('القالب لا يحتوي على مواد خام');
                    }
                    
                    $insufficientMaterials = [];
                    foreach ($rawMaterials as $material) {
                        $quantityColumnValue = isset($material['quantity']) ? $material['quantity'] : ($material['quantity_per_unit'] ?? 0);
                        $requiredQty = floatval($quantityColumnValue) * $quantity;
                        $supplierIdForCheck = $material['supplier_id'] ?? null;
                        
                        // التحقق من توفر المادة حسب نوعها
                        switch ($material['material_type']) {
                            case 'honey_raw':
                            case 'honey_filtered':
                                $stockColumn = $material['material_type'] === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM($stockColumn) as total FROM honey_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM($stockColumn) as total FROM honey_stock"
                                    );
                                }
                                break;
                            case 'olive_oil':
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM(quantity) as total FROM olive_oil_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM(quantity) as total FROM olive_oil_stock"
                                    );
                                }
                                break;
                            case 'beeswax':
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM beeswax_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM beeswax_stock"
                                    );
                                }
                                break;
                            case 'derivatives':
                                if ($supplierIdForCheck) {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM derivatives_stock WHERE supplier_id = ?",
                                        [$supplierIdForCheck]
                                    );
                                } else {
                                    $available = $db->queryOne(
                                        "SELECT SUM(weight) as total FROM derivatives_stock"
                                    );
                                }
                                break;
                            default:
                                $available = ['total' => PHP_FLOAT_MAX]; // للمواد الأخرى لا نتحقق
                        }
                        
                        $availableQty = floatval($available['total'] ?? 0);
                        if ($availableQty < $requiredQty) {
                            $insufficientMaterials[] = [
                                'name' => $material['material_name'],
                                'required' => $requiredQty,
                                'available' => $availableQty,
                                'unit' => $material['unit']
                            ];
                        }
                    }
                    
                    if (!empty($insufficientMaterials)) {
                        $errorMsg = 'المواد الخام غير كافية: ';
                        $errors = [];
                        foreach ($insufficientMaterials as $mat) {
                            $errors[] = $mat['name'] . ' (مطلوب: ' . $mat['required'] . ' ' . $mat['unit'] . ', متوفر: ' . $mat['available'] . ' ' . $mat['unit'] . ')';
                        }
                        throw new Exception($errorMsg . implode(', ', $errors));
                    }
                    
                    // التحقق من أدوات التعبئة
                    $packagingItems = $db->query(
                        "SELECT * FROM template_packaging WHERE template_id = ?",
                        [$templateId]
                    );
                    
                    foreach ($packagingItems as $pkg) {
                        $requiredQty = floatval($pkg['quantity_per_unit']) * $quantity;
                        $available = $db->queryOne(
                            "SELECT quantity FROM packaging_materials WHERE id = ?",
                            [$pkg['packaging_material_id']]
                        );
                        
                        $availableQty = floatval($available['quantity'] ?? 0);
                        if ($availableQty < $requiredQty) {
                            $pkgInfo = $db->queryOne("SELECT name FROM packaging_materials WHERE id = ?", [$pkg['packaging_material_id']]);
                            throw new Exception('أدوات التعبئة غير كافية: ' . ($pkgInfo['name'] ?? 'مادة تعبئة') . ' (مطلوب: ' . $requiredQty . ', متوفر: ' . $availableQty . ')');
                        }
                    }
                    
                } else {
                    // قالب قديم
                    $template = $db->queryOne(
                        "SELECT pt.*, pr.id as product_id, pr.name as product_name
                         FROM product_templates pt
                         LEFT JOIN products pr ON pt.product_name = pr.name
                         WHERE pt.id = ?",
                        [$templateId]
                    );
                    
                    if (!$template) {
                        throw new Exception('القالب غير موجود');
                    }
                    
                    // التحقق من توفر المكونات قبل الإنتاج
                    $materialsCheck = checkMaterialsAvailability($db, $templateId, $quantity);
                    if (!$materialsCheck['available']) {
                        throw new Exception('المكونات غير متوفرة: ' . $materialsCheck['message']);
                    }
                }
                
                // إنشاء المنتج إذا لم يكن موجوداً
                $productId = $template['product_id'] ?? 0;
                if ($productId <= 0) {
                    // البحث عن منتج بنفس الاسم
                    $existingProduct = $db->queryOne("SELECT id FROM products WHERE name = ? LIMIT 1", [$template['product_name']]);
                    if ($existingProduct) {
                        $productId = $existingProduct['id'];
                    } else {
                        // إنشاء منتج جديد
                        $result = $db->execute(
                            "INSERT INTO products (name, category, status, unit) VALUES (?, 'finished', 'active', 'قطعة')",
                            [$template['product_name']]
                        );
                        $productId = $result['insert_id'];
                    }
                }
                
                // الحصول على أدوات التعبئة والموردين من القالب
                $packagingIds = [];
                $allSuppliers = []; // جميع الموردين المستخدمين في المنتج
                $materialsConsumption = [
                    'raw' => [],
                    'packaging' => []
                ];
                
                if ($isUnifiedTemplate) {
                    // قالب موحد - الحصول على أدوات التعبئة
                    $packagingNameExpression = getColumnSelectExpression('template_packaging', 'packaging_name', 'packaging_name', 'tp');
                    $packagingItems = $db->query(
                        "SELECT tp.id, tp.packaging_material_id, {$packagingNameExpression}, tp.quantity_per_unit,
                                pm.name as packaging_db_name, pm.unit as packaging_unit, pm.product_id as packaging_product_id
                         FROM template_packaging tp 
                         LEFT JOIN packaging_materials pm ON pm.id = tp.packaging_material_id
                         WHERE tp.template_id = ?",
                        [$templateId]
                    );
                    $packagingIds = array_filter(array_map(function($p) { return $p['packaging_material_id'] ?? null; }, $packagingItems));
                    
                    $honeySupplierId = null;
                    $packagingSupplierId = null;
                    $honeyVariety = null;
                    $usingSubmittedSuppliers = !empty($materialSuppliers);
                    
                    if ($usingSubmittedSuppliers) {
                        // الموردون المحددون من النموذج المتقدم
                        foreach ($packagingItems as $pkg) {
                            $pkgKey = 'pack_' . ($pkg['packaging_material_id'] ?? $pkg['id']);
                            $selectedSupplierId = $materialSuppliers[$pkgKey] ?? 0;
                            if (empty($selectedSupplierId)) {
                                throw new Exception('يرجى اختيار مورد لكل أداة تعبئة قبل إنشاء التشغيلة.');
                            }
                            
                            $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                            if (!$supplierInfo) {
                                throw new Exception('مورد غير صالح لأداة التعبئة: ' . ($pkg['packaging_name'] ?? 'غير معروف'));
                            }
                            
                            $allSuppliers[] = [
                                'id' => $supplierInfo['id'],
                                'name' => $supplierInfo['name'],
                                'type' => $supplierInfo['type'],
                                'material' => $pkg['packaging_name'] ?? 'أداة تعبئة'
                            ];
                            
                            if (!$packagingSupplierId) {
                                $packagingSupplierId = $supplierInfo['id'];
                            }

                            $packagingQuantityPerUnit = isset($pkg['quantity_per_unit']) ? (float)$pkg['quantity_per_unit'] : 1.0;
                            $packagingName = $pkg['packaging_name'] ?? $pkg['packaging_db_name'] ?? 'مادة تعبئة';
                            $packagingUnit = $pkg['packaging_unit'] ?? 'قطعة';
                            $packagingProductId = isset($pkg['packaging_product_id']) ? (int)$pkg['packaging_product_id'] : null;
                            if (!empty($pkg['packaging_material_id'])) {
                                $materialsConsumption['packaging'][] = [
                                    'material_id' => (int)$pkg['packaging_material_id'],
                                    'quantity' => $packagingQuantityPerUnit * $quantity,
                                    'name' => $packagingName,
                                    'unit' => $packagingUnit,
                                    'product_id' => $packagingProductId
                                ];
                            }
                        }
                        
                        $rawSuppliers = $db->query(
                            "SELECT id, material_name, material_type, honey_variety, quantity FROM template_raw_materials WHERE template_id = ?",
                            [$templateId]
                        );
                        
                        foreach ($rawSuppliers as $materialRow) {
                            $rawKey = 'raw_' . $materialRow['id'];
                            $selectedSupplierId = $materialSuppliers[$rawKey] ?? 0;
                            if (empty($selectedSupplierId)) {
                                throw new Exception('يرجى اختيار مورد لكل مادة خام قبل إنشاء التشغيلة.');
                            }
                            
                            $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                            if (!$supplierInfo) {
                                throw new Exception('مورد غير صالح للمادة الخام: ' . ($materialRow['material_name'] ?? 'غير معروف'));
                            }
                            
                            $materialType = $materialRow['material_type'] ?? '';
                            $selectedHoneyVariety = $materialHoneyVarieties[$rawKey] ?? '';
                            if (in_array($materialType, ['honey_raw', 'honey_filtered'], true) && $selectedHoneyVariety === '') {
                                throw new Exception('يرجى تحديد نوع العسل للمادة الخام: ' . ($materialRow['material_name'] ?? 'عسل'));
                            }

                            $detectedHoneyVariety = $selectedHoneyVariety !== '' ? $selectedHoneyVariety : ($materialRow['honey_variety'] ?? null);
                            
                            $materialDisplay = $materialRow['material_name'] ?? 'مادة خام';
                            if (!empty($detectedHoneyVariety)) {
                                $materialDisplay .= ' (' . $detectedHoneyVariety . ')';
                                if (!$honeyVariety) {
                                    $honeyVariety = $detectedHoneyVariety;
                                }
                            }
                            
                            $allSuppliers[] = [
                                'id' => $supplierInfo['id'],
                                'name' => $supplierInfo['name'],
                                'type' => $supplierInfo['type'],
                                'material' => $materialDisplay,
                                'honey_variety' => $detectedHoneyVariety
                            ];
                            
                            if (!$honeySupplierId && in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                                $honeySupplierId = $supplierInfo['id'];
                            }

                            $rawQuantityPerUnit = isset($materialRow['quantity']) ? (float)$materialRow['quantity'] : (isset($materialRow['quantity_per_unit']) ? (float)$materialRow['quantity_per_unit'] : 0.0);
                            $materialUnit = $materialRow['unit'] ?? 'كجم';
                            $materialsConsumption['raw'][] = [
                                'template_material_id' => (int)$materialRow['id'],
                                'supplier_id' => $selectedSupplierId,
                                'material_type' => $materialType,
                                'material_name' => $materialRow['material_name'] ?? '',
                                'honey_variety' => $detectedHoneyVariety,
                                'unit' => $materialUnit,
                                'display_name' => $materialDisplay,
                                'quantity' => $rawQuantityPerUnit * $quantity
                            ];
                        }

                        if (!$packagingSupplierId) {
                            foreach ($materialSuppliers as $key => $value) {
                                if (strpos($key, 'pack_') === 0 && $value > 0) {
                                    $packagingSupplierId = $value;
                                    break;
                                }
                            }
                        }
                    } else {
                        // fallback إلى النظام القديم (معلومات الموردين من القالب نفسه)
                        $hasHoneyVarietyColumn = false;
                        $templateRawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
                        if (!empty($templateRawMaterialsTableCheck)) {
                            $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
                            $hasHoneyVarietyColumn = !empty($honeyVarietyColumnCheck);
                        }
                        
                        $selectColumns = "DISTINCT supplier_id, material_type, material_name, quantity, unit";
                        if ($hasHoneyVarietyColumn) {
                            $selectColumns .= ", honey_variety";
                        }
                        
                        $rawMaterialsWithSuppliers = $db->query(
                            "SELECT {$selectColumns} FROM template_raw_materials WHERE template_id = ? AND supplier_id IS NOT NULL",
                            [$templateId]
                        );
                        
                        $honeyVariety = null;
                        
                        foreach ($rawMaterialsWithSuppliers as $material) {
                            $supplierInfo = $db->queryOne(
                                "SELECT id, name, type FROM suppliers WHERE id = ?",
                                [$material['supplier_id']]
                            );
                            if ($supplierInfo) {
                                $materialDisplay = $material['material_name'];
                                
                                if ($hasHoneyVarietyColumn 
                                    && ($material['material_type'] === 'honey_raw' || $material['material_type'] === 'honey_filtered') 
                                    && !empty($material['honey_variety'])) {
                                    $materialDisplay .= ' (' . $material['honey_variety'] . ')';
                                    if (!$honeyVariety) {
                                        $honeyVariety = $material['honey_variety'];
                                    }
                                }
                                
                                $allSuppliers[] = [
                                    'id' => $supplierInfo['id'],
                                    'name' => $supplierInfo['name'],
                                    'type' => $supplierInfo['type'],
                                    'material' => $materialDisplay,
                                    'honey_variety' => ($hasHoneyVarietyColumn && isset($material['honey_variety'])) ? $material['honey_variety'] : null
                                ];
                                
                                if (!$honeySupplierId && in_array($supplierInfo['type'], ['honey'])) {
                                    $honeySupplierId = $supplierInfo['id'];
                                }

                                $rawQuantityPerUnit = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
                                if ($rawQuantityPerUnit > 0) {
                                    $materialsConsumption['raw'][] = [
                                        'template_material_id' => null,
                                        'supplier_id' => $material['supplier_id'] ?? null,
                                        'material_type' => $material['material_type'] ?? '',
                                        'material_name' => $material['material_name'] ?? '',
                                        'honey_variety' => ($hasHoneyVarietyColumn && isset($material['honey_variety'])) ? $material['honey_variety'] : null,
                                        'unit' => $material['unit'] ?? 'كجم',
                                        'display_name' => $materialDisplay,
                                        'quantity' => $rawQuantityPerUnit * $quantity
                                    ];
                                }
                            }
                        }
                        
                        if (!empty($allSuppliers)) {
                            foreach ($allSuppliers as $sup) {
                                if (!$packagingSupplierId && $sup['type'] === 'packaging') {
                                    $packagingSupplierId = $sup['id'];
                                }
                            }
                        }

                        if (!$packagingSupplierId && !empty($materialSuppliers)) {
                            foreach ($materialSuppliers as $key => $value) {
                                if (strpos($key, 'pack_') === 0 && $value > 0) {
                                    $packagingSupplierId = $value;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    // قالب قديم وأنواع القوالب المبسطة (عسل، زيت، شمع، مشتقات)
                    $packagingMaterials = $db->query(
                        "SELECT ptp.id, ptp.packaging_material_id, ptp.packaging_name, ptp.quantity_per_unit,
                                pm.name as packaging_db_name, pm.unit as packaging_unit, pm.product_id as packaging_product_id
                         FROM product_template_packaging ptp
                         LEFT JOIN packaging_materials pm ON pm.id = ptp.packaging_material_id
                         WHERE ptp.template_id = ?",
                        [$templateId]
                    );
                    $packagingIds = array_filter(array_map(function($p) { return $p['packaging_material_id'] ?? null; }, $packagingMaterials));
                    
                    $packagingSupplierId = null;

                    foreach ($packagingMaterials as $legacyPkg) {
                        $pkgKey = 'pack_' . ($legacyPkg['packaging_material_id'] ?? $legacyPkg['id']);
                        $selectedSupplierId = $materialSuppliers[$pkgKey] ?? 0;
                        if (empty($selectedSupplierId)) {
                            throw new Exception('يرجى اختيار مورد لكل أداة تعبئة مستخدمة في القالب.');
                        }

                        $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                        if (!$supplierInfo) {
                            throw new Exception('مورد غير صالح لأداة التعبئة: ' . ($legacyPkg['packaging_name'] ?? 'غير معروف'));
                        }

                        $materialLabel = $legacyPkg['packaging_name'] ?? 'أداة تعبئة';
                        $allSuppliers[] = [
                            'id' => $supplierInfo['id'],
                            'name' => $supplierInfo['name'],
                            'type' => $supplierInfo['type'],
                            'material' => $materialLabel
                        ];

                    if (!$packagingSupplierId) {
                            $packagingSupplierId = $supplierInfo['id'];
                        }

                        if (!empty($legacyPkg['packaging_material_id'])) {
                            $legacyPackagingName = $legacyPkg['packaging_name'] ?? $legacyPkg['packaging_db_name'] ?? 'مادة تعبئة';
                            $legacyPackagingUnit = $legacyPkg['packaging_unit'] ?? 'قطعة';
                            $legacyPackagingProductId = isset($legacyPkg['packaging_product_id']) ? (int)$legacyPkg['packaging_product_id'] : null;
                            $materialsConsumption['packaging'][] = [
                                'material_id' => (int)$legacyPkg['packaging_material_id'],
                                'quantity' => (float)($legacyPkg['quantity_per_unit'] ?? 1.0) * $quantity,
                                'name' => $legacyPackagingName,
                                'unit' => $legacyPackagingUnit,
                                'product_id' => $legacyPackagingProductId
                            ];
                        }
                    }
                    
                    // معالجة المواد الرئيسية حسب نوع القالب
                    switch ($templateType) {
                        case 'olive_oil':
                            $oliveSupplierId = $materialSuppliers['olive_main'] ?? 0;
                            if (empty($oliveSupplierId)) {
                                throw new Exception('يرجى اختيار مورد زيت الزيتون.');
                            }
                            $oliveSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$oliveSupplierId]);
                            if (!$oliveSupplier) {
                                throw new Exception('مورد زيت الزيتون غير صالح.');
                            }
                            $allSuppliers[] = [
                                'id' => $oliveSupplier['id'],
                                'name' => $oliveSupplier['name'],
                                'type' => $oliveSupplier['type'],
                                'material' => 'زيت زيتون'
                            ];
                            break;

                        case 'beeswax':
                            $beeswaxSupplierId = $materialSuppliers['beeswax_main'] ?? 0;
                            if (empty($beeswaxSupplierId)) {
                                throw new Exception('يرجى اختيار مورد شمع العسل.');
                            }
                            $beeswaxSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$beeswaxSupplierId]);
                            if (!$beeswaxSupplier) {
                                throw new Exception('مورد شمع العسل غير صالح.');
                            }
                            $allSuppliers[] = [
                                'id' => $beeswaxSupplier['id'],
                                'name' => $beeswaxSupplier['name'],
                                'type' => $beeswaxSupplier['type'],
                                'material' => 'شمع عسل'
                            ];
                            break;

                        case 'derivatives':
                            $derivativeSupplierId = $materialSuppliers['derivative_main'] ?? 0;
                            if (empty($derivativeSupplierId)) {
                                throw new Exception('يرجى اختيار مورد المشتق.');
                            }
                            $derivativeSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$derivativeSupplierId]);
                            if (!$derivativeSupplier) {
                                throw new Exception('مورد المشتق غير صالح.');
                            }
                            $allSuppliers[] = [
                                'id' => $derivativeSupplier['id'],
                                'name' => $derivativeSupplier['name'],
                                'type' => $derivativeSupplier['type'],
                                'material' => 'مشتق'
                            ];
                            break;

                        case 'honey':
                        case 'legacy':
                        default:
                            $honeySupplierIdSelected = $materialSuppliers['honey_main'] ?? 0;
                            if ((float)($template['honey_quantity'] ?? 0) > 0) {
                                if (empty($honeySupplierIdSelected)) {
                                    throw new Exception('يرجى اختيار مورد العسل.');
                                }
                                $honeySupp = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$honeySupplierIdSelected]);
                                if (!$honeySupp) {
                                    throw new Exception('مورد العسل غير صالح.');
                                }
                            $selectedHoneyVariety = $materialHoneyVarieties['honey_main'] ?? '';
                            if ($selectedHoneyVariety === '') {
                                throw new Exception('يرجى تحديد نوع العسل المستخدم في التشغيلة.');
                            }
                            $honeySupplierId = $honeySupp['id'];
                            $honeyVariety = $selectedHoneyVariety;
                                $allSuppliers[] = [
                                    'id' => $honeySupp['id'],
                                    'name' => $honeySupp['name'],
                                    'type' => $honeySupp['type'],
                                'material' => 'عسل (' . $selectedHoneyVariety . ')',
                                'honey_variety' => $selectedHoneyVariety
                                ];

                            $materialsConsumption['raw'][] = [
                                'template_material_id' => null,
                                'supplier_id' => $honeySupplierId,
                                'material_type' => 'honey_filtered',
                                'material_name' => 'عسل',
                                'honey_variety' => $selectedHoneyVariety,
                                'unit' => 'كجم',
                                'display_name' => 'عسل (' . $selectedHoneyVariety . ')',
                                'quantity' => (float)($template['honey_quantity'] ?? 0) * $quantity
                            ];
                            }
                            break;
                    }

                    // المواد الخام الإضافية المرتبطة بالقالب القديم
                    $legacyRawMaterials = $db->query(
                        "SELECT id, material_name, quantity_per_unit, unit 
                         FROM product_template_raw_materials 
                         WHERE template_id = ?",
                        [$templateId]
                    );

                    foreach ($legacyRawMaterials as $legacyRaw) {
                        $rawKey = 'raw_' . $legacyRaw['id'];
                        $selectedSupplierId = $materialSuppliers[$rawKey] ?? 0;
                        if (empty($selectedSupplierId)) {
                            throw new Exception('يرجى اختيار مورد للمادة الخام: ' . ($legacyRaw['material_name'] ?? 'مادة خام'));
                        }

                        $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                        if (!$supplierInfo) {
                            throw new Exception('مورد غير صالح للمادة الخام: ' . ($legacyRaw['material_name'] ?? 'غير معروف'));
                        }

                        $allSuppliers[] = [
                            'id' => $supplierInfo['id'],
                            'name' => $supplierInfo['name'],
                            'type' => $supplierInfo['type'],
                            'material' => $legacyRaw['material_name'] ?? 'مادة خام'
                        ];
                    }
                }
                
                // التحقق من توفر نوع العسل لدى المورد المحدد
                $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
                foreach ($materialsConsumption['raw'] as $rawItem) {
                    $materialType = $rawItem['material_type'] ?? '';
                    if (!in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                        continue;
                    }
                    
                    $supplierForHoney = $rawItem['supplier_id'] ?? null;
                    $requiredHoneyQuantity = (float)($rawItem['quantity'] ?? 0);
                    if (!$supplierForHoney || $requiredHoneyQuantity <= 0) {
                        continue;
                    }
                    
                    if ($honeyStockTableCheck) {
                        $stockColumn = $materialType === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                        $params = [$supplierForHoney];
                        $honeySql = "SELECT {$stockColumn} AS available_quantity, honey_variety 
                                     FROM honey_stock 
                                     WHERE supplier_id = ?";
                        
                        if (!empty($rawItem['honey_variety'])) {
                            $honeySql .= " AND honey_variety = ?";
                            $params[] = $rawItem['honey_variety'];
                        }
                        
                        $honeySql .= " ORDER BY {$stockColumn} DESC LIMIT 1";
                        $supplierHoney = $db->queryOne($honeySql, $params);
                        
                        if (!$supplierHoney) {
                            $varietyLabel = $rawItem['honey_variety'] ?: ($rawItem['material_name'] ?: 'العسل المطلوب');
                            throw new Exception('المورد المحدد لا يمتلك مخزوناً من نوع العسل: ' . $varietyLabel);
                        }
                        
                        $availableHoney = (float)($supplierHoney['available_quantity'] ?? 0);
                        if ($availableHoney < $requiredHoneyQuantity) {
                            $varietyLabel = $supplierHoney['honey_variety'] ?? $rawItem['honey_variety'] ?? ($rawItem['material_name'] ?: 'العسل المطلوب');
                            throw new Exception(sprintf(
                                'الكمية المتاحة من %s لدى المورد المختار غير كافية. مطلوب %.2f كجم، متوفر %.2f كجم.',
                                $varietyLabel,
                                $requiredHoneyQuantity,
                                $availableHoney
                            ));
                        }
                    }
                }
                
                // تحديد user_id
                $selectedUserId = $currentUser['role'] === 'production' ? $currentUser['id'] : intval($_POST['user_id'] ?? $currentUser['id']);
                
                // 3. الحصول على عمال الإنتاج الحاضرين خلال اليوم
                $workersList = [];
                $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                if (!empty($attendanceTableCheck)) {
                    // الحصول على عمال الإنتاج الذين سجلوا حضور اليوم
                    $presentWorkers = $db->query(
                        "SELECT DISTINCT user_id 
                         FROM attendance_records 
                         WHERE date = ? 
                         AND check_in_time IS NOT NULL 
                         AND user_id IN (SELECT id FROM users WHERE role = 'production' AND status = 'active')
                         ORDER BY check_in_time DESC",
                        [$productionDate]
                    );
                    
                    foreach ($presentWorkers as $worker) {
                        $workersList[] = intval($worker['user_id']);
                    }
                }
                
                // إذا لم يوجد عمال حاضرين، إضافة المستخدم الحالي فقط
                if (empty($workersList)) {
                    $workersList = [$selectedUserId];
                }
                
                // 4. الملاحظات: إنشاء ملاحظات تلقائية تشمل جميع الموردين
                $batchNotes = trim($_POST['batch_notes'] ?? '');
                if (empty($batchNotes)) {
                    $notesParts = ['تم إنشاءه من قالب: ' . $template['product_name']];
                    
                    // إضافة جميع الموردين إلى الملاحظات
                    if (!empty($allSuppliers)) {
                        $supplierNames = [];
                        foreach ($allSuppliers as $supplier) {
                            $supplierNames[] = $supplier['name'] . ' (' . $supplier['material'] . ')';
                        }
                        $notesParts[] = 'الموردين: ' . implode(', ', $supplierNames);
                    }
                    
                    $batchNotes = implode(' | ', $notesParts);
                }
                
                // إنشاء سجل إنتاج واحد للتشغيلة
                $columns = ['product_id', 'quantity'];
                $values = [$productId, $quantity]; // الكمية الكاملة
                $placeholders = ['?', '?'];
                
                $columns[] = $dateColumn;
                $values[] = $productionDate;
                $placeholders[] = '?';
                
                if ($userIdColumn) {
                    $columns[] = $userIdColumn;
                    $values[] = $selectedUserId;
                    $placeholders[] = '?';
                }
                
                $columns[] = 'status';
                $values[] = 'completed';
                $placeholders[] = '?';
                
                $sql = "INSERT INTO production (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $result = $db->execute($sql, $values);
                $productionId = $result['insert_id'];
                
                // إنشاء رقم تشغيلة واحد لجميع المنتجات
                $batchResult = createBatchNumber(
                    $productId,
                    $productionId,
                    $productionDate,
                    $honeySupplierId, // مورد العسل (للتوافق)
                    $packagingIds,
                    $packagingSupplierId, 
                    $workersList, // العمال الحاضرين (تلقائي)
                    $quantity, // الكمية الكاملة
                    null, // expiry_date
                    $batchNotes, // الملاحظات (تلقائية)
                    $currentUser['id'],
                    $allSuppliers, // جميع الموردين مع المواد الخام
                    $honeyVariety ?? null // نوع العسل المستخدم
                );
                
                if (!$batchResult['success']) {
                    throw new Exception('فشل في إنشاء رقم التشغيلة: ' . $batchResult['message']);
                }
                
                $batchNumber = $batchResult['batch_number'];

                storeProductionMaterialsUsage($productionId, $materialsConsumption['raw'], $materialsConsumption['packaging']);

                try {
                    foreach ($materialsConsumption['raw'] as $rawItem) {
                        $deductQuantity = (float)($rawItem['quantity'] ?? 0);
                        if ($deductQuantity <= 0) {
                            continue;
                        }

                        $materialType = $rawItem['material_type'] ?? '';
                        $supplierForDeduction = $rawItem['supplier_id'] ?? null;
                        $materialName = $rawItem['material_name'] ?? '';

                        switch ($materialType) {
                            case 'honey_raw':
                            case 'honey_filtered':
                                if ($supplierForDeduction) {
                                    $stockColumn = $materialType === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                                    $db->execute(
                                        "UPDATE honey_stock 
                                         SET {$stockColumn} = GREATEST({$stockColumn} - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'olive_oil':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE olive_oil_stock 
                                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'beeswax':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE beeswax_stock 
                                         SET weight = GREATEST(weight - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'derivatives':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE derivatives_stock 
                                         SET weight = GREATEST(weight - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'nuts':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE nuts_stock 
                                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'legacy':
                                // لا يوجد تعريف واضح للمورد في القوالب القديمة، يتم تجاهل الخصم تلقائياً
                                break;
                            default:
                                if ($materialName !== '') {
                                    $matchedProduct = $db->queryOne(
                                        "SELECT id FROM products WHERE name = ? LIMIT 1",
                                        [$materialName]
                                    );
                                    if ($matchedProduct) {
                                        $db->execute(
                                            "UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?",
                                            [$deductQuantity, $matchedProduct['id']]
                                        );
                                    }
                                }
                                break;
                        }
                    }

                    foreach ($materialsConsumption['packaging'] as $packItem) {
                        $packMaterialId = $packItem['material_id'] ?? null;
                        $packQuantity = (float)($packItem['quantity'] ?? 0);
                        if ($packMaterialId && $packQuantity > 0) {
                            $db->execute(
                                "UPDATE packaging_materials 
                                 SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                 WHERE id = ?",
                                [$packQuantity, $packMaterialId]
                            );
                        }
                    }
                } catch (Exception $stockWarning) {
                    error_log('Production stock deduction warning: ' . $stockWarning->getMessage());
                }
                
                // إنشاء باركودات متعددة حسب الكمية
                $batchNumbersToPrint = [];
                for ($i = 0; $i < $quantity; $i++) {
                    $batchNumbersToPrint[] = $batchNumber;
                }
                
                $db->commit();
                
                logAudit($currentUser['id'], 'create_from_template', 'production', $productionId, null, [
                    'template_id' => $templateId,
                    'quantity' => $quantity,
                    'batch_number' => $batchNumber,
                    'honey_supplier_id' => $honeySupplierId,
                    'packaging_supplier_id' => $packagingSupplierId
                ]);
                
                // حفظ أرقام التشغيلة في session لعرضها في modal الطباعة
                $_SESSION['created_batch_numbers'] = $batchNumbersToPrint; // باركودات متعددة حسب الكمية
                $_SESSION['created_batch_product_name'] = $template['product_name'];
                $_SESSION['created_batch_quantity'] = $quantity;
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم إنشاء تشغيلة إنتاج بنجاح! رقم التشغيلة: ' . $batchNumber . ' (الكمية: ' . $quantity . ' قطعة)';
                $redirectParams = ['page' => 'production', 'show_barcode_modal' => '1'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'حدث خطأ في إنشاء الإنتاج: ' . $e->getMessage();
                error_log("Create from template error: " . $e->getMessage());
            }
        }
    }
    // تم نقل إنشاء قوالب المنتجات إلى صفحة مخزن الخامات
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 5; // 5 عناصر لكل صفحة
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = $_GET['search'] ?? '';
$productId = $_GET['product_id'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// بناء استعلام البحث
$whereConditions = ['1=1'];
$params = [];

if ($search) {
    if ($userIdColumn) {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ? OR u.full_name LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    } else {
        $whereConditions[] = "(p.id LIKE ? OR pr.name LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
}

if ($productId) {
    $whereConditions[] = "p.product_id = ?";
    $params[] = intval($productId);
}

if ($status) {
    $whereConditions[] = "p.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(p.$dateColumn) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(p.$dateColumn) <= ?";
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

// حساب العدد الإجمالي
if ($userIdColumn) {
    $countSql = "SELECT COUNT(*) as total 
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 LEFT JOIN users u ON p.{$userIdColumn} = u.id
                 WHERE $whereClause";
} else {
    $countSql = "SELECT COUNT(*) as total 
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 WHERE $whereClause";
}

$totalResult = $db->queryOne($countSql, $params);
$totalProduction = $totalResult['total'] ?? 0;
$totalPages = ceil($totalProduction / $perPage);

// حساب إجمالي الكمية المنتجة
$totalQuantitySql = str_replace('COUNT(*) as total', 'COALESCE(SUM(p.quantity), 0) as total', $countSql);
$totalQuantityResult = $db->queryOne($totalQuantitySql, $params);
$totalQuantity = floatval($totalQuantityResult['total'] ?? 0);

// الحصول على البيانات
if ($userIdColumn) {
    $sql = "SELECT p.*, 
                   pr.name as product_name, 
                   pr.category as product_category,
                   u.full_name as worker_name,
                   u.username as worker_username
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id
            LEFT JOIN users u ON p.{$userIdColumn} = u.id
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
} else {
    $sql = "SELECT p.*, 
                   pr.name as product_name, 
                   pr.category as product_category,
                   'غير محدد' as worker_name,
                   'غير محدد' as worker_username
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
}

$params[] = $perPage;
$params[] = $offset;

$productions = $db->query($sql, $params);

// الحصول على المنتجات والعمال
$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' ORDER BY name");
$workers = $db->query("SELECT id, username, full_name FROM users WHERE role = 'production' AND status = 'active' ORDER BY username");

// الحصول على الموردين
$suppliers = [];
$suppliersTableCheck = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
if (!empty($suppliersTableCheck)) {
    $suppliers = $db->query("SELECT id, name, type FROM suppliers WHERE status = 'active' ORDER BY name");
}

// إنشاء جداول قوالب المنتجات إذا لم تكن موجودة
try {
    $templatesTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
    if (empty($templatesTableCheck)) {
        // إنشاء جدول product_templates
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
              `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية العسل بالجرام',
              `status` enum('active','inactive') DEFAULT 'active',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // التحقق من وجود عمود honey_quantity وإضافته إذا لم يكن موجوداً
        $honeyColumnCheck = $db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'honey_quantity'");
        if (empty($honeyColumnCheck)) {
            try {
                $db->execute("
                    ALTER TABLE `product_templates` 
                    ADD COLUMN `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية العسل بالجرام' 
                    AFTER `product_name`
                ");
                error_log("Added honey_quantity column to product_templates table");
            } catch (Exception $e) {
                error_log("Error adding honey_quantity column: " . $e->getMessage());
            }
        }
    }
    
    // إنشاء جدول product_template_packaging
    $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
    if (empty($packagingTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_template_packaging` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `packaging_material_id` int(11) DEFAULT NULL,
              `packaging_name` varchar(255) NOT NULL,
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`),
              KEY `packaging_material_id` (`packaging_material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إنشاء جدول product_template_raw_materials
    $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
    if (empty($rawMaterialsTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة (مثل: مكسرات، لوز، إلخ)',
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالجرام',
              `unit` varchar(50) DEFAULT 'جرام',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // التحقق من وجود الجداول المرتبطة حتى لو كان product_templates موجوداً
        $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
        if (empty($packagingTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_packaging` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `packaging_material_id` int(11) DEFAULT NULL,
                  `packaging_name` varchar(255) NOT NULL,
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`),
                  KEY `packaging_material_id` (`packaging_material_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
        if (empty($rawMaterialsTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة (مثل: مكسرات، لوز، إلخ)',
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالجرام',
                  `unit` varchar(50) DEFAULT 'جرام',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
} catch (Exception $e) {
    error_log("Product templates tables creation error: " . $e->getMessage());
}

// الحصول على قوالب المنتجات من جميع الأقسام
$templates = [];

// 0. القوالب الموحدة الجديدة (متعددة المواد)
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    // التحقق من وجود جدول template_raw_materials وعمود honey_variety
    $templateRawMaterialsCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
    $hasHoneyVariety = false;
    
    if (!empty($templateRawMaterialsCheck)) {
        $honeyVarietyCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
        $hasHoneyVariety = !empty($honeyVarietyCheck);
        
        // إضافة العمود إذا لم يكن موجوداً
        if (!$hasHoneyVariety) {
            try {
                $db->execute("
                    ALTER TABLE `template_raw_materials` 
                    ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'نوع العسل (سدر، جبلي، إلخ)' 
                    AFTER `supplier_id`
                ");
                $hasHoneyVariety = true;
            } catch (Exception $e) {
                error_log("Error adding honey_variety column: " . $e->getMessage());
            }
        }
    }
    
    $unifiedTemplates = $db->query(
        "SELECT upt.*, 
                'unified' as template_type,
                u.full_name as creator_name
         FROM unified_product_templates upt
         LEFT JOIN users u ON upt.created_by = u.id
         WHERE upt.status = 'active'
         ORDER BY upt.created_at DESC"
    );
    
    foreach ($unifiedTemplates as &$template) {
        // الحصول على المواد الخام للقالب
        if (!empty($templateRawMaterialsCheck)) {
            $selectColumns = "material_type, material_name, quantity, unit";
            if ($hasHoneyVariety) {
                $selectColumns = "material_type, material_name, honey_variety, quantity, unit";
            }
            
            $rawMaterials = $db->query(
                "SELECT {$selectColumns}
                 FROM template_raw_materials 
                 WHERE template_id = ?",
                [$template['id']]
            );
        } else {
            $rawMaterials = [];
        }
        
        $template['material_details'] = [];
        foreach ($rawMaterials as $material) {
            // ترجمة اسم المادة إلى العربية
            $materialNameArabic = $material['material_name'];
            
            // قاموس الترجمة للمواد الشائعة
            $materialTranslations = [
                ':honey_filtered' => 'عسل مصفى',
                ':honey_raw' => 'عسل خام',
                'honey_filtered' => 'عسل مصفى',
                'honey_raw' => 'عسل خام',
                ':olive_oil' => 'زيت زيتون',
                'olive_oil' => 'زيت زيتون',
                ':beeswax' => 'شمع عسل',
                'beeswax' => 'شمع عسل',
                ':derivatives' => 'مشتقات',
                'derivatives' => 'مشتقات',
                ':nuts' => 'مكسرات',
                'nuts' => 'مكسرات',
                ':other' => 'مواد أخرى',
                'other' => 'مواد أخرى'
            ];
            
            // تطبيق الترجمة إذا وُجدت
            if (isset($materialTranslations[$materialNameArabic])) {
                $materialNameArabic = $materialTranslations[$materialNameArabic];
            }
            
            $materialDisplay = $materialNameArabic;
            
            // إضافة نوع العسل إن وُجد (فقط إذا كان العمود موجوداً)
            if ($hasHoneyVariety 
                && ($material['material_type'] === 'honey_raw' || $material['material_type'] === 'honey_filtered') 
                && !empty($material['honey_variety'])) {
                $materialDisplay .= ' (' . $material['honey_variety'] . ')';
            }
            
            $template['material_details'][] = [
                'type' => $materialDisplay,
                'quantity' => $material['quantity'],
                'unit' => $material['unit']
            ];
        }
    }
    
    // تصفية القوالب: إزالة القوالب الفارغة (التي ليس لها مواد خام)
    $unifiedTemplates = array_filter($unifiedTemplates, function($template) {
        return !empty($template['material_details']);
    });
    
    $templates = array_merge($templates, $unifiedTemplates);
}

// 1. قوالب العسل (القوالب القديمة)
$honeyTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
if (!empty($honeyTemplatesCheck)) {
    $honeyTemplates = $db->query(
        "SELECT pt.*, 
                'honey' as template_type,
                u.full_name as creator_name
         FROM product_templates pt
         LEFT JOIN users u ON pt.created_by = u.id
         WHERE pt.status = 'active'
         ORDER BY pt.created_at DESC"
    );
    foreach ($honeyTemplates as &$template) {
        $template['material_details'] = [
            ['type' => 'عسل', 'quantity' => $template['honey_quantity'], 'unit' => 'جرام']
        ];
    }
    $templates = array_merge($templates, $honeyTemplates);
}

// 2. قوالب زيت الزيتون
$oliveOilTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_product_templates'");
if (!empty($oliveOilTemplatesCheck)) {
    $oliveOilTemplates = $db->query(
        "SELECT id, product_name, olive_oil_quantity, created_at,
                'olive_oil' as template_type
         FROM olive_oil_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($oliveOilTemplates as &$template) {
        $template['material_details'] = [
            ['type' => 'زيت زيتون', 'quantity' => $template['olive_oil_quantity'], 'unit' => 'لتر']
        ];
        $template['creator_name'] = '';
    }
    $templates = array_merge($templates, $oliveOilTemplates);
}

// 3. قوالب شمع العسل
$beeswaxTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'beeswax_product_templates'");
if (!empty($beeswaxTemplatesCheck)) {
    $beeswaxTemplates = $db->query(
        "SELECT id, product_name, beeswax_weight, created_at,
                'beeswax' as template_type
         FROM beeswax_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($beeswaxTemplates as &$template) {
        $template['material_details'] = [
            ['type' => 'شمع عسل', 'quantity' => $template['beeswax_weight'], 'unit' => 'كجم']
        ];
        $template['creator_name'] = '';
    }
    $templates = array_merge($templates, $beeswaxTemplates);
}

// 4. قوالب المشتقات
$derivativesTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_product_templates'");
if (!empty($derivativesTemplatesCheck)) {
    $derivativesTemplates = $db->query(
        "SELECT id, product_name, derivative_type, derivative_weight, created_at,
                'derivatives' as template_type
         FROM derivatives_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($derivativesTemplates as &$template) {
        // ترجمة نوع المشتق إلى العربية
        $derivativeTypeArabic = $template['derivative_type'];
        $derivativeTranslations = [
            'royal_jelly' => 'غذاء ملكات النحل',
            'propolis' => 'البروبوليس',
            'pollen' => 'حبوب اللقاح',
            'other' => 'مشتق آخر'
        ];
        if (isset($derivativeTranslations[$derivativeTypeArabic])) {
            $derivativeTypeArabic = $derivativeTranslations[$derivativeTypeArabic];
        }
        
        $template['material_details'] = [
            ['type' => $derivativeTypeArabic, 'quantity' => $template['derivative_weight'], 'unit' => 'كجم']
        ];
        $template['creator_name'] = '';
    }
    $templates = array_merge($templates, $derivativesTemplates);
}

// الحصول على أدوات التعبئة للاستخدام في modal إنشاء القالب
$packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$packagingMaterials = [];
if (!empty($packagingTableCheck)) {
    $packagingMaterials = $db->query(
        "SELECT id, name, type, quantity, unit FROM packaging_materials WHERE status = 'active' ORDER BY name"
    );
}

$productionReportsTodayDate = date('Y-m-d');
$productionReportsMonthStart = date('Y-m-01');
$productionReportsToday = getConsumptionSummary($productionReportsTodayDate, $productionReportsTodayDate);
$productionReportsMonth = getConsumptionSummary($productionReportsMonthStart, $productionReportsTodayDate);

if (!function_exists('productionPageRenderConsumptionTable')) {
    function productionPageRenderConsumptionTable(array $items, bool $includeCategory = false): void
    {
        if (empty($items)) {
            echo '<div class="text-center text-muted py-4">لا توجد بيانات متاحة للفترة المحددة.</div>';
            return;
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-hover align-middle">';
        echo '<thead class="table-light"><tr>';
        echo '<th>المادة</th>';
        if ($includeCategory) {
            echo '<th>الفئة</th>';
        }
        echo '<th>الاستهلاك</th><th>الوارد</th><th>الصافي</th><th>الحركات</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($item['sub_category'] ?? '-', ENT_QUOTES, 'UTF-8');
            $totalOut = number_format((float)($item['total_out'] ?? 0), 3);
            $totalIn = number_format((float)($item['total_in'] ?? 0), 3);
            $net = number_format((float)($item['net'] ?? 0), 3);
            $movements = (int)($item['movements'] ?? 0);

            echo '<tr>';
            echo '<td>' . $name . '</td>';
            if ($includeCategory) {
                echo '<td><span class="badge bg-secondary text-white">' . $category . '</span></td>';
            }
            echo '<td>' . $totalOut . '</td>';
            echo '<td>' . $totalIn . '</td>';
            echo '<td>' . $net . '</td>';
            echo '<td>' . $movements . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}

if (!function_exists('productionPageSumMovements')) {
    function productionPageSumMovements(array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        return array_sum(array_map(static function ($row) {
            return (int)($row['movements'] ?? 0);
        }, $items));
    }
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i><?php echo isset($lang['production']) ? $lang['production'] : 'الإنتاج'; ?></h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductionModal">
        <i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add_production']) ? $lang['add_production'] : 'إضافة إنتاج'; ?>
    </button>
</div>

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

<div class="mb-4">
    <div class="production-tab-toggle" role="tablist" aria-label="التنقل بين أقسام صفحة الإنتاج">
        <button type="button"
                class="btn btn-outline-primary production-tab-btn active"
                data-production-tab="records"
                aria-pressed="true"
                aria-controls="productionRecordsSection">
            <i class="bi bi-list-task me-1"></i>
            سجلات الإنتاج
        </button>
        <button type="button"
                class="btn btn-outline-primary production-tab-btn"
                data-production-tab="reports"
                aria-pressed="false"
                aria-controls="productionReportsSection">
            <i class="bi bi-graph-up-arrow me-1"></i>
            تقارير الإنتاج
        </button>
    </div>
</div>

<div id="productionRecordsSection">

<!-- جدول الإنتاج -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i><?php echo isset($lang['production_list']) ? $lang['production_list'] : 'قائمة الإنتاج'; ?> (<?php echo $totalProduction; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?php echo isset($lang['id']) ? $lang['id'] : 'رقم'; ?></th>
                        <th><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?></th>
                        <th><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?></th>
                        <th><?php echo isset($lang['worker']) ? $lang['worker'] : 'العامل'; ?></th>
                        <th><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?></th>
                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                        <th><?php echo isset($lang['actions']) ? $lang['actions'] : 'الإجراءات'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productions)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                <?php echo isset($lang['no_production']) ? $lang['no_production'] : 'لا توجد سجلات إنتاج'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productions as $prod): ?>
                            <tr>
                                <td>#<?php echo $prod['id']; ?></td>
                                <td><?php echo htmlspecialchars($prod['product_name'] ?? 'غير محدد'); ?></td>
                                <td><?php echo number_format($prod['quantity'] ?? 0, 2); ?></td>
                                <td><?php echo htmlspecialchars($prod['worker_name'] ?? $prod['worker_username'] ?? 'غير محدد'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($prod[$dateColumn] ?? $prod['created_at'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        $status = $prod['status'] ?? 'pending';
                                        echo $status === 'approved' ? 'success' : 
                                            ($status === 'rejected' ? 'danger' : 
                                            ($status === 'completed' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php echo isset($lang[$status]) ? $lang[$status] : ucfirst($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info" onclick="viewProduction(<?php echo $prod['id']; ?>)" title="<?php echo isset($lang['view']) ? $lang['view'] : 'عرض'; ?>">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if ($currentUser['role'] === 'production' && $prod['status'] === 'pending'): ?>
                                        <button class="btn btn-sm btn-warning" onclick="editProduction(<?php echo $prod['id']; ?>)" title="<?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteProduction(<?php echo $prod['id']; ?>)" title="<?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php elseif (in_array($currentUser['role'], ['accountant', 'manager'])): ?>
                                        <button class="btn btn-sm btn-warning" onclick="editProduction(<?php echo $prod['id']; ?>)" title="<?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteProduction(<?php echo $prod['id']; ?>)" title="<?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
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
                    <a class="page-link" href="?page=production&p=<?php echo $pageNum - 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=production&p=1">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=production&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=production&p=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=production&p=<?php echo $pageNum + 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
            <div class="text-center mt-2 text-muted">
                <small>عرض <?php echo min($offset + 1, $totalProduction); ?> - <?php echo min($offset + $perPage, $totalProduction); ?> من <?php echo $totalProduction; ?> سجل</small>
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- قسم قوالب المنتجات -->
<div class="card shadow-sm mt-5">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>قوالب المنتجات - إنشاء إنتاج من قالب</h5>
        <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-light btn-sm">
            <i class="bi bi-plus-circle me-2"></i>إدارة القوالب في مخزن الخامات
        </a>
    </div>
    <?php if (!empty($templates)): ?>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($templates as $template): ?>
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="card shadow-sm h-100 template-card" style="border-left: 4px solid #0dcaf0; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;"
                         data-template-id="<?php echo $template['id']; ?>"
                         data-template-name="<?php echo htmlspecialchars($template['product_name']); ?>"
                         data-template-type="<?php echo htmlspecialchars($template['template_type'] ?? 'legacy'); ?>"
                         onclick="openCreateFromTemplateModal(this)">
                        <div class="card-body p-3">
                            <?php 
                            $templateTypeLabels = [
                                'unified' => 'متعدد المواد',
                                'honey' => 'عسل',
                                'olive_oil' => 'زيت زيتون',
                                'beeswax' => 'شمع عسل',
                                'derivatives' => 'مشتقات'
                            ];
                            $typeLabel = $templateTypeLabels[$template['template_type']] ?? 'غير محدد';
                            $typeColors = [
                                'unified' => 'dark',
                                'honey' => 'warning',
                                'olive_oil' => 'success',
                                'beeswax' => 'primary',
                                'derivatives' => 'secondary'
                            ];
                            $typeColor = $typeColors[$template['template_type']] ?? 'secondary';
                            ?>
                            
                            <!-- Header مدمج -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0 text-info fw-bold" style="font-size: 0.95rem;">
                                    <i class="bi bi-box-seam me-2"></i>
                                    <?php echo htmlspecialchars($template['product_name']); ?>
                                </h6>
                            </div>
                            
                            <!-- Badge نوع المادة -->
                            <div class="mb-3">
                                <span class="badge bg-<?php echo $typeColor; ?> text-white" style="font-size: 0.75rem; padding: 0.35rem 0.65rem;">
                                    <?php echo $typeLabel; ?>
                                </span>
                            </div>
                            
                            <!-- المكونات -->
                            <?php if (!empty($template['material_details'])): ?>
                                <?php foreach ($template['material_details'] as $material): ?>
                                    <div class="d-flex align-items-center mb-2 p-2 bg-light rounded">
                                        <i class="bi bi-droplet-fill text-<?php echo $typeColor; ?> me-2" style="font-size: 0.9rem;"></i>
                                        <small class="text-muted me-2" style="font-size: 0.8rem;"><?php echo htmlspecialchars($material['type']); ?>:</small>
                                        <small class="fw-bold text-<?php echo $typeColor; ?>" style="font-size: 0.85rem;">
                                            <?php echo number_format($material['quantity'], 2); ?> <?php echo htmlspecialchars($material['unit']); ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <!-- Footer -->
                            <div class="text-center mt-3 pt-3 border-top">
                                <span class="badge bg-success" style="font-size: 0.8rem; padding: 0.5rem 1rem;">
                                    <i class="bi bi-arrow-right-circle me-2"></i>
                                    اضغط للإنتاج
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="text-muted">لا توجد قوالب منتجات</h5>
            <p class="text-muted">قم بإنشاء قوالب المنتجات من صفحة مخزن الخامات</p>
            <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-primary">
                <i class="bi bi-box-seam me-2"></i>الذهاب إلى مخزن الخامات
            </a>
        </div>
    <?php endif; ?>
</div>

</div>

</div>

<div id="productionReportsSection" class="d-none">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-day me-2"></i>ملخص اليوم</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($productionReportsToday['date_from'] ?? $productionReportsTodayDate); ?>
                        —
                        <?php echo htmlspecialchars($productionReportsToday['date_to'] ?? $productionReportsTodayDate); ?>
                    </p>
                </div>
                <span class="badge bg-light text-primary border border-primary-subtle">
                    آخر تحديث: <?php echo htmlspecialchars($productionReportsToday['generated_at'] ?? date('Y-m-d H:i:s')); ?>
                </span>
            </div>
            <div class="production-summary-grid mt-3">
                <div class="summary-card">
                    <span class="summary-label">استهلاك أدوات التعبئة</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsToday['packaging']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">استهلاك المواد الخام</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsToday['raw']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">الصافي الكلي</span>
                    <span class="summary-value text-success">
                        <?php
                        $todayNet = (float)($productionReportsToday['packaging']['net'] ?? 0) + (float)($productionReportsToday['raw']['net'] ?? 0);
                        echo number_format($todayNet, 3);
                        ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">إجمالي الحركات</span>
                    <span class="summary-value text-secondary">
                        <?php
                        $todayMovements = productionPageSumMovements($productionReportsToday['packaging']['items'] ?? [])
                            + productionPageSumMovements($productionReportsToday['raw']['items'] ?? []);
                        echo number_format($todayMovements);
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة المستهلكة اليوم</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsToday['packaging']['items'] ?? []); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-droplet-half me-2"></i>المواد الخام المستهلكة اليوم</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsToday['raw']['items'] ?? [], true); ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-month me-2"></i>ملخص الشهر الحالي</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($productionReportsMonth['date_from'] ?? $productionReportsMonthStart); ?>
                        —
                        <?php echo htmlspecialchars($productionReportsMonth['date_to'] ?? $productionReportsTodayDate); ?>
                    </p>
                </div>
                <span class="badge bg-light text-primary border border-primary-subtle">
                    آخر تحديث: <?php echo htmlspecialchars($productionReportsMonth['generated_at'] ?? date('Y-m-d H:i:s')); ?>
                </span>
            </div>
            <div class="production-summary-grid mt-3">
                <div class="summary-card">
                    <span class="summary-label">إجمالي استهلاك التعبئة</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsMonth['packaging']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">إجمالي استهلاك المواد الخام</span>
                    <span class="summary-value text-primary">
                        <?php echo number_format((float)($productionReportsMonth['raw']['total_out'] ?? 0), 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">الصافي الشهري</span>
                    <span class="summary-value text-success">
                        <?php
                        $monthNet = (float)($productionReportsMonth['packaging']['net'] ?? 0) + (float)($productionReportsMonth['raw']['net'] ?? 0);
                        echo number_format($monthNet, 3);
                        ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">إجمالي الحركات</span>
                    <span class="summary-value text-secondary">
                        <?php
                        $monthMovements = productionPageSumMovements($productionReportsMonth['packaging']['items'] ?? [])
                            + productionPageSumMovements($productionReportsMonth['raw']['items'] ?? []);
                        echo number_format($monthMovements);
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة للشهر الحالي</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsMonth['packaging']['items'] ?? []); ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-droplet-half me-2"></i>المواد الخام للشهر الحالي</span>
        </div>
        <div class="card-body">
            <?php if (!empty($productionReportsMonth['raw']['sub_totals'])): ?>
                <div class="mb-3">
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($productionReportsMonth['raw']['sub_totals'] as $subTotal): ?>
                            <span class="badge bg-light text-dark border">
                                <?php echo htmlspecialchars($subTotal['label'] ?? 'غير مصنف'); ?>:
                                <?php echo number_format((float)($subTotal['total_out'] ?? 0), 3); ?>
                                (صافي <?php echo number_format((float)($subTotal['net'] ?? 0), 3); ?>)
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php productionPageRenderConsumptionTable($productionReportsMonth['raw']['items'] ?? [], true); ?>
        </div>
    </div>
</div>

<!-- Modal إنشاء إنتاج من قالب -->
<div class="modal fade" id="createFromTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-xl production-template-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>إنشاء تشغيلة إنتاج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createFromTemplateForm">
                <input type="hidden" name="action" value="create_from_template">
                <input type="hidden" name="template_id" id="template_id">
                <input type="hidden" name="template_mode" id="template_mode" value="advanced">
                <input type="hidden" name="template_type" id="template_type" value="">
                <div class="modal-body production-template-body">
                    <!-- معلومات المنتج -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-box-seam me-2"></i>معلومات المنتج</h6>
                        <div class="mb-3">
                            <label class="form-label fw-bold">اسم المنتج</label>
                            <input type="text" class="form-control" id="template_product_name" readonly>
                        </div>
                    </div>
                    
                    <!-- معلومات التشغيلة -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-info-circle me-2"></i>معلومات التشغيلة</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">الكمية المراد إنتاجها <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" class="form-control" min="1" required value="1">
                                <small class="text-muted">سيتم إنشاء رقم تشغيلة واحد (LOT) لجميع المنتجات</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">تاريخ الإنتاج <span class="text-danger">*</span></label>
                                <input type="date" name="production_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- الموردون الديناميكيون -->
                    <div class="mb-3 section-block d-none" id="templateSuppliersWrapper">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-truck me-2"></i>الموردون لكل مادة <span class="text-danger">*</span>
                        </h6>
                        <p class="text-muted small mb-3" id="templateSuppliersHint">يرجى اختيار المورد المناسب لكل مادة سيتم استخدامها في هذه التشغيلة.</p>
                        <div class="row g-3" id="templateSuppliersContainer"></div>
                    </div>
                    
                    <!-- عمال الإنتاج الحاضرين -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-people me-2"></i>عمال الإنتاج الحاضرين</h6>
                        <?php
                        // الحصول على العمال الحاضرين اليوم
                        $presentWorkersToday = [];
                        $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                        if (!empty($attendanceTableCheck)) {
                            $presentWorkersToday = $db->query(
                                "SELECT DISTINCT u.id, u.username, u.full_name 
                                 FROM attendance_records ar
                                 JOIN users u ON ar.user_id = u.id
                                 WHERE ar.date = ? 
                                 AND ar.check_in_time IS NOT NULL 
                                 AND u.role = 'production' 
                                 AND u.status = 'active'
                                 ORDER BY ar.check_in_time DESC",
                                [date('Y-m-d')]
                            );
                        }
                        ?>
                        <?php if (!empty($presentWorkersToday)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>العمال الحاضرين اليوم:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($presentWorkersToday as $worker): ?>
                                        <li><?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <small class="text-muted">سيتم ربط التشغيلة تلقائياً بجميع عمال الإنتاج الحاضرين اليوم</small>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا يوجد عمال إنتاج حاضرين اليوم. سيتم ربط التشغيلة بالعامل الحالي فقط.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php endif; ?>
                    
                    <!-- ملاحظات -->
                    <div class="mb-3 section-block">
                        <label class="form-label fw-bold">ملاحظات (اختياري)</label>
                        <textarea name="batch_notes" class="form-control" rows="3" 
                                  placeholder="سيتم إنشاء الملاحظات تلقائياً بناءً على البيانات المحددة"></textarea>
                        <small class="text-muted">إذا تركتها فارغة، سيتم إنشاء ملاحظات تلقائية تتضمن معلومات الموردين والقالب</small>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>إنشاء التشغيلة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal طباعة الباركودات -->
<div class="modal fade" id="printBarcodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>طباعة الباركودات</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    تم إنشاء <strong id="barcode_quantity">0</strong> سجل إنتاج بنجاح مع أرقام التشغيلة
                </div>
                <div class="mb-3">
                    <label class="form-label">اسم المنتج</label>
                    <input type="text" class="form-control" id="barcode_product_name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">عدد الباركودات المراد طباعتها</label>
                    <input type="number" class="form-control" id="barcode_print_quantity" min="1" value="1">
                    <small class="text-muted">سيتم طباعة نفس رقم التشغيلة بعدد المرات المحدد</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">أرقام التشغيلة</label>
                    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                        <div id="batch_numbers_list"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary" onclick="printBarcodes()">
                    <i class="bi bi-printer me-2"></i>طباعة الباركودات
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة إنتاج -->
<div class="modal fade" id="addProductionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add_production']) ? $lang['add_production'] : 'إضافة إنتاج'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addProductionForm">
                <input type="hidden" name="action" value="add_production">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?> <span class="text-danger">*</span></label>
                            <select name="product_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_product']) ? $lang['select_product'] : 'اختر المنتج'; ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">الوحدة</label>
                            <select name="unit" class="form-select">
                                <option value="kg">كجم</option>
                                <option value="g">جرام</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'تاريخ الإنتاج'; ?> <span class="text-danger">*</span></label>
                            <input type="date" name="production_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['worker']) ? $lang['worker'] : 'العامل'; ?> <span class="text-danger">*</span></label>
                            <select name="user_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_worker']) ? $lang['select_worker'] : 'اختر العامل'; ?></option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo $worker['id']; ?>">
                                        <?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['materials_used']) ? $lang['materials_used'] : 'المواد المستخدمة'; ?></label>
                            <textarea name="materials_used" class="form-control" rows="3" placeholder="<?php echo isset($lang['materials_used_placeholder']) ? $lang['materials_used_placeholder'] : 'وصف المواد المستخدمة...'; ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'ملاحظات'; ?></label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="<?php echo isset($lang['notes_placeholder']) ? $lang['notes_placeholder'] : 'ملاحظات إضافية...'; ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل إنتاج -->
<div class="modal fade" id="editProductionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?php echo isset($lang['edit_production']) ? $lang['edit_production'] : 'تعديل إنتاج'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editProductionForm">
                <input type="hidden" name="action" value="update_production">
                <input type="hidden" name="production_id" id="edit_production_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?> <span class="text-danger">*</span></label>
                            <select name="product_id" id="edit_product_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_product']) ? $lang['select_product'] : 'اختر المنتج'; ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" id="edit_quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">الوحدة</label>
                            <select name="unit" id="edit_unit" class="form-select">
                                <option value="kg">كجم</option>
                                <option value="g">جرام</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'تاريخ الإنتاج'; ?> <span class="text-danger">*</span></label>
                            <input type="date" name="production_date" id="edit_production_date" class="form-control" required>
                        </div>
                        <?php if (in_array($currentUser['role'], ['accountant', 'manager'])): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="pending"><?php echo isset($lang['pending']) ? $lang['pending'] : 'معلق'; ?></option>
                                <option value="approved"><?php echo isset($lang['approved']) ? $lang['approved'] : 'موافق عليه'; ?></option>
                                <option value="rejected"><?php echo isset($lang['rejected']) ? $lang['rejected'] : 'مرفوض'; ?></option>
                                <option value="completed"><?php echo isset($lang['completed']) ? $lang['completed'] : 'مكتمل'; ?></option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['materials_used']) ? $lang['materials_used'] : 'المواد المستخدمة'; ?></label>
                            <textarea name="materials_used" id="edit_materials_used" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'ملاحظات'; ?></label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['update']) ? $lang['update'] : 'تحديث'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* ⚙️ تحسين عرض نموذج إنشاء التشغيلة */
.production-template-dialog {
    width: min(960px, 94vw);
    height: calc(100vh - 2rem);
    margin: 1rem auto;
    display: flex;
    flex-direction: column;
}

.production-template-dialog .modal-content {
    border-radius: 16px;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.production-template-dialog .modal-header,
.production-template-dialog .modal-footer {
    padding: 0.75rem 1.25rem;
    flex-shrink: 0;
}

.production-template-body {
    padding: 1rem 1.1rem;
    flex: 1 1 auto;
    overflow-y: auto;
}

@media (max-width: 991.98px) {
    .production-template-dialog {
        width: 100%;
        height: 100vh;
        margin: 0;
        border-radius: 0;
    }

    .production-template-dialog .modal-content {
        border-radius: 0;
    }
}

.production-template-body .section-block {
    background: #f9fafb;
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    margin-bottom: 0.75rem;
}

.production-template-body .section-heading {
    font-size: 1rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.production-template-body .form-label {
    font-size: 0.95rem;
    margin-bottom: 0.35rem;
}

.production-template-body .small {
    font-size: 0.75rem;
}

.production-template-body .alert {
    margin-bottom: 0.75rem;
    padding: 0.75rem 0.9rem;
}

.production-template-body .row.g-3 > [class*="col-"] {
    margin-bottom: 0;
}

/* 🎯 ضبط تبويبات الصفحة وتقارير الإنتاج */
.production-tab-toggle {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.production-tab-toggle .production-tab-btn {
    flex: 1 1 200px;
    min-width: 160px;
    padding: 0.65rem 1.2rem !important;
    border-radius: 12px !important;
    border: 1px solid rgba(29, 78, 216, 0.35) !important;
    background: #ffffff !important;
    color: #1d4ed8 !important;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08) !important;
    font-weight: 600 !important;
    transition: all 0.25s ease !important;
}

.production-tab-toggle .production-tab-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(59, 130, 246, 0.25) !important;
}

.production-tab-toggle .production-tab-btn.active {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    color: #ffffff !important;
    border-color: transparent !important;
    box-shadow: 0 12px 26px rgba(37, 99, 235, 0.35) !important;
}

#productionReportsSection {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

#productionReportsSection .card {
    border-radius: 16px !important;
}

#productionReportsSection .production-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 1rem;
}

#productionReportsSection .summary-card {
    background: #f8fafc;
    border-radius: 14px;
    padding: 1.1rem;
    border: 1px solid rgba(15, 23, 42, 0.05);
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

#productionReportsSection .summary-label {
    font-size: 0.85rem;
    color: #6b7280;
    letter-spacing: 0.3px;
}

#productionReportsSection .summary-value {
    font-size: 1.45rem;
    font-weight: 700;
    line-height: 1.2;
}

#productionReportsSection .summary-value.text-secondary {
    color: #475569 !important;
}

#productionReportsSection .badge {
    font-size: 0.8rem;
    padding: 0.45rem 0.75rem;
    border-radius: 10px;
}

#productionReportsSection .table {
    font-size: 0.9rem;
}

#productionReportsSection .table th {
    font-size: 0.85rem;
    letter-spacing: 0.4px;
}

#productionReportsSection .table td {
    vertical-align: middle;
    font-size: 0.85rem;
}

/* 🎨 تنسيق الألوان والظلال - تدرجات الأزرق */

/* البطاقات والكروت */
.card {
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 2px 15px rgba(13, 110, 253, 0.08) !important;
    transition: all 0.3s ease !important;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(13, 110, 253, 0.15) !important;
}

/* رأس البطاقات - تدرج أزرق جميل */
.card-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    border: none !important;
    border-radius: 12px 12px 0 0 !important;
    padding: 1rem 1.5rem !important;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3) !important;
}

.card-header.bg-primary,
.card-header.bg-info,
.card-header.bg-success {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35) !important;
}

/* الأزرار - تدرجات أزرق حديثة */
.btn-primary,
.btn-info,
.btn-success,
.btn-outline-primary,
.btn-outline-info,
.btn-outline-success {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
    border: none !important;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4) !important;
    transition: all 0.3s ease !important;
    border-radius: 8px !important;
    padding: 0.5rem 1.5rem !important;
    font-weight: 500 !important;
    color: #fff !important;
}

.btn-primary:hover,
.btn-info:hover,
.btn-success:hover,
.btn-outline-primary:hover,
.btn-outline-info:hover,
.btn-outline-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 99, 235, 0.6) !important;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
}

.btn-primary:focus,
.btn-info:focus,
.btn-success:focus,
.btn-outline-primary:focus,
.btn-outline-info:focus,
.btn-outline-success:focus {
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.35) !important;
}

/* الجداول */
.table {
    border-radius: 8px !important;
    overflow: hidden !important;
}

.table thead th {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important;
    color: white !important;
    border: none !important;
    font-weight: 600 !important;
    padding: 1rem !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table tbody tr {
    transition: all 0.2s ease !important;
}

.table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.06) 0%, rgba(59, 130, 246, 0.06) 100%) !important;
    transform: scale(1.01);
}

/* رسائل التنبيه */
.alert {
    border: none !important;
    border-radius: 10px !important;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1) !important;
}

.alert-success {
    background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%) !important;
    color: #155724 !important;
}

.alert-danger {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    color: #721c24 !important;
}

.alert-info {
    background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%) !important;
    color: #004085 !important;
}

/* Modal */
.modal-content {
    border: none !important;
    border-radius: 15px !important;
    box-shadow: 0 10px 40px rgba(13, 110, 253, 0.2) !important;
}

.modal-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    color: white !important;
    border: none !important;
    border-radius: 15px 15px 0 0 !important;
    padding: 1.25rem 1.5rem !important;
}

.modal-header .btn-close {
    filter: brightness(0) invert(1) !important;
}

.modal-footer {
    border-top: 1px solid rgba(102, 126, 234, 0.1) !important;
    padding: 1rem 1.5rem !important;
    background: rgba(37, 99, 235, 0.04) !important;
}

/* Badges */
.badge {
    border-radius: 6px !important;
    padding: 0.4rem 0.8rem !important;
    font-weight: 500 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
}

.badge.bg-success {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%) !important;
}

.badge.bg-danger {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%) !important;
}

.badge.bg-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

.badge.bg-info {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%) !important;
}

.badge.bg-warning {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    color: #856404 !important;
}

/* Form Controls */
.form-control:focus, .form-select:focus {
    border-color: #667eea !important;
    box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25) !important;
}

/* تحسينات تصميم modal إنشاء القالب */
#createTemplateModal .modal-body {
    padding: 1.5rem;
}

#createTemplateModal .form-label {
    margin-bottom: 0.5rem;
    font-size: 0.95rem;
    font-weight: 500;
    color: #495057;
}

#createTemplateModal .border {
    border-color: rgba(102, 126, 234, 0.2) !important;
    border-radius: 8px !important;
}

#createTemplateModal .form-check {
    padding: 0.5rem;
    border-radius: 8px;
    transition: all 0.2s;
}

#createTemplateModal .form-check:hover {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(59, 130, 246, 0.08) 100%);
}

#createTemplateModal .form-check-input:checked ~ .form-check-label {
    font-weight: 600;
    color: #667eea;
}

#createTemplateModal .badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

/* عناوين الصفحة */
h2, h3, h4, h5 {
    color: #2d3748 !important;
    font-weight: 600 !important;
}

/* تأثيرات إضافية */
.shadow-sm {
    box-shadow: 0 2px 15px rgba(13, 110, 253, 0.08) !important;
}

.shadow {
    box-shadow: 0 4px 20px rgba(13, 110, 253, 0.12) !important;
}

.shadow-lg {
    box-shadow: 0 10px 40px rgba(13, 110, 253, 0.15) !important;
}

/* Animations */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.card, .alert, .btn {
    animation: fadeIn 0.3s ease-in-out;
}
</style>

<?php
$honeyStockDataForJs = [];
try {
    $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
    if (!empty($honeyStockTableCheck)) {
        $honeyStockRows = $db->query("
            SELECT 
                supplier_id, 
                honey_variety, 
                COALESCE(raw_honey_quantity, 0) AS raw_quantity, 
                COALESCE(filtered_honey_quantity, 0) AS filtered_quantity
            FROM honey_stock
        ");
        foreach ($honeyStockRows as $row) {
            $supplierId = (int)($row['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }
            if (!isset($honeyStockDataForJs[$supplierId])) {
                $honeyStockDataForJs[$supplierId] = [
                    'all' => [],
                    'honey_raw' => [],
                    'honey_filtered' => []
                ];
            }
            $varietyName = trim((string)($row['honey_variety'] ?? ''));
            $entry = [
                'variety' => $varietyName,
                'raw_quantity' => (float)($row['raw_quantity'] ?? 0),
                'filtered_quantity' => (float)($row['filtered_quantity'] ?? 0)
            ];
            $honeyStockDataForJs[$supplierId]['all'][] = $entry;
            if ($entry['raw_quantity'] > 0) {
                $honeyStockDataForJs[$supplierId]['honey_raw'][] = $entry;
            }
            if ($entry['filtered_quantity'] > 0) {
                $honeyStockDataForJs[$supplierId]['honey_filtered'][] = $entry;
            }
        }
    }
} catch (Exception $honeyDataException) {
    error_log('Production honey stock fetch error: ' . $honeyDataException->getMessage());
}
?>

<script>
window.productionSuppliers = <?php
$suppliersForJs = is_array($suppliers) ? $suppliers : [];
echo json_encode(array_map(function($supplier) {
    return [
        'id' => (int)($supplier['id'] ?? 0),
        'name' => $supplier['name'] ?? '',
        'type' => $supplier['type'] ?? ''
    ];
}, $suppliersForJs), JSON_UNESCAPED_UNICODE);
?>;
window.honeyStockData = <?php echo json_encode($honeyStockDataForJs, JSON_UNESCAPED_UNICODE); ?>;
let currentTemplateMode = 'advanced';

const HONEY_COMPONENT_TYPES = ['honey_raw', 'honey_filtered', 'honey_general', 'honey_main'];

function isHoneyComponent(component) {
    if (!component) {
        return false;
    }
    const type = (component.type || '').toString();
    if (type && HONEY_COMPONENT_TYPES.includes(type)) {
        return true;
    }
    const key = (component.key || '').toString();
    return key.startsWith('honey_');
}

function getSuppliersForComponent(component) {
    const suppliers = window.productionSuppliers || [];
    if (!component) {
        return suppliers;
    }
    const type = (component.type || '').toString();
    const key = (component.key || '').toString();

    const filterByTypes = (allowedTypes) => suppliers.filter(supplier => allowedTypes.includes(supplier.type));

    if (isHoneyComponent(component)) {
        return filterByTypes(['honey']);
    }

    if (type === 'packaging' || key.startsWith('pack_')) {
        return filterByTypes(['packaging']);
    }

    if (type === 'olive_oil' || key.startsWith('olive')) {
        return filterByTypes(['olive_oil']);
    }

    if (type === 'beeswax' || key.startsWith('beeswax')) {
        return filterByTypes(['beeswax']);
    }

    if (type === 'derivatives' || key.startsWith('derivative')) {
        return filterByTypes(['derivatives']);
    }

    if (type === 'nuts' || key.startsWith('nuts')) {
        return filterByTypes(['nuts']);
    }

    return suppliers;
}

function normalizeSupplierKey(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const numeric = Number(value);
    if (!Number.isNaN(numeric) && numeric > 0) {
        return String(numeric);
    }
    return String(value);
}

function populateHoneyVarietyOptions(inputEl, datalistEl, supplierId, component) {
    if (!inputEl || !datalistEl) {
        return;
    }

    const normalizedKey = normalizeSupplierKey(supplierId);
    if (!normalizedKey) {
        datalistEl.innerHTML = '';
        inputEl.value = '';
        inputEl.disabled = true;
        inputEl.placeholder = 'اختر المورد أولاً';
        inputEl.dataset.defaultApplied = '';
        return;
    }

    const honeyData = window.honeyStockData || {};
    const supplierData = honeyData[normalizedKey] ?? honeyData[String(parseInt(normalizedKey, 10))] ?? null;
    const componentType = (component?.type || '').toString();

    let items = [];
    if (supplierData) {
        if (componentType === 'honey_raw' && Array.isArray(supplierData.honey_raw) && supplierData.honey_raw.length) {
            items = supplierData.honey_raw;
        } else if (componentType === 'honey_filtered' && Array.isArray(supplierData.honey_filtered) && supplierData.honey_filtered.length) {
            items = supplierData.honey_filtered;
        } else if (Array.isArray(supplierData.all)) {
            items = supplierData.all;
        }
    }

    datalistEl.innerHTML = '';
    const uniqueVarieties = new Set();
    items.forEach(item => {
        const varietyName = item && item.variety ? String(item.variety) : '';
        if (!varietyName || uniqueVarieties.has(varietyName)) {
            return;
        }
        uniqueVarieties.add(varietyName);
        const option = document.createElement('option');
        option.value = varietyName;
        datalistEl.appendChild(option);
    });

    inputEl.disabled = false;
    inputEl.placeholder = uniqueVarieties.size > 0
        ? 'اختر أو اكتب نوع العسل'
        : 'اكتب نوع العسل المتوفر لدى المورد';

    if (!inputEl.dataset.defaultApplied) {
        const defaultValue = inputEl.dataset.defaultValue || '';
        if (defaultValue !== '') {
            if (uniqueVarieties.size === 0 || uniqueVarieties.has(defaultValue)) {
                inputEl.value = defaultValue;
            }
        }
        inputEl.dataset.defaultApplied = '1';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('[data-production-tab]');
    const sections = {
        records: document.getElementById('productionRecordsSection'),
        reports: document.getElementById('productionReportsSection')
    };

    tabButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const target = button.getAttribute('data-production-tab');
            if (!target || !sections[target]) {
                return;
            }

            tabButtons.forEach(function(btn) {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
            });

            Object.keys(sections).forEach(function(key) {
                if (sections[key]) {
                    sections[key].classList.add('d-none');
                }
            });

            button.classList.add('active');
            button.setAttribute('aria-pressed', 'true');
            sections[target].classList.remove('d-none');
        });
    });
});

function renderTemplateSuppliers(details) {
    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const hintText = document.getElementById('templateSuppliersHint');

    if (!container || !wrapper || !modeInput) {
        return;
    }

    const components = Array.isArray(details?.components) ? details.components : [];

    container.innerHTML = '';

    if (components.length === 0) {
        wrapper.classList.remove('d-none');
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    لا توجد مواد مرتبطة بالقالب. يرجى تحديث القالب وإضافة المواد المطلوبة.
                </div>
            </div>
        `;
        if (hintText) {
            hintText.textContent = 'لا توجد مواد لعرضها.';
        }
        currentTemplateMode = 'advanced';
        modeInput.value = 'advanced';
        return;
    }

    details.components.forEach(function(component) {
        const col = document.createElement('div');
        col.className = 'col-md-6';

        const label = document.createElement('label');
        label.className = 'form-label fw-bold';
        label.textContent = component.label || component.name || 'مادة';

        const helper = document.createElement('small');
        helper.className = 'text-muted d-block mb-2';
        helper.textContent = component.description || '';

        const select = document.createElement('select');
        select.className = 'form-select';
        const componentKey = (component.key || component.name || ('component_' + Math.random().toString(36).slice(2)));
        select.name = 'material_suppliers[' + componentKey + ']';
        select.dataset.role = 'component-supplier';
        select.required = component.required !== false;
        select.dataset.componentType = component.type || '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = component.placeholder || 'اختر المورد';
        select.appendChild(placeholderOption);

        const suppliersForComponent = getSuppliersForComponent(component);
        const suppliersList = suppliersForComponent.length ? suppliersForComponent : (window.productionSuppliers || []);

        suppliersList.forEach(function(supplier) {
            const option = document.createElement('option');
            option.value = supplier.id;
            option.textContent = supplier.name;
            if (component.default_supplier && parseInt(component.default_supplier, 10) === supplier.id) {
                option.selected = true;
            }
            select.appendChild(option);
        });
        if (suppliersList.length === 0) {
            const noSupplierOption = document.createElement('option');
            noSupplierOption.value = '';
            noSupplierOption.disabled = true;
            noSupplierOption.textContent = 'لا يوجد مورد مناسب - راجع قائمة الموردين';
            select.appendChild(noSupplierOption);
        }

        col.appendChild(label);
        if (helper.textContent.trim() !== '') {
            col.appendChild(helper);
        }
        col.appendChild(select);

        if (isHoneyComponent(component)) {
            const honeyWrapper = document.createElement('div');
            honeyWrapper.className = 'mt-2';

            const honeyLabel = document.createElement('label');
            honeyLabel.className = 'form-label fw-bold';
            honeyLabel.textContent = 'نوع العسل للمورد المختار';

            const honeyInput = document.createElement('input');
            honeyInput.type = 'text';
            honeyInput.className = 'form-control';
            honeyInput.name = 'material_honey_varieties[' + componentKey + ']';
            honeyInput.required = true;
            honeyInput.dataset.role = 'honey-variety-input';
            honeyInput.dataset.defaultValue = component.honey_variety ? component.honey_variety : '';
            honeyInput.placeholder = 'اختر المورد أولاً';
            honeyInput.disabled = true;

            const honeyHelper = document.createElement('small');
            honeyHelper.className = 'text-muted d-block mt-1';
            honeyHelper.textContent = 'اختر أو اكتب نوع العسل كما هو متوفر لدى المورد.';

            const datalist = document.createElement('datalist');
            const sanitizedKey = componentKey.toString().replace(/[^a-zA-Z0-9_-]/g, '');
            datalist.id = 'honey-variety-list-' + sanitizedKey + '-' + Math.random().toString(36).slice(2, 6);
            honeyInput.setAttribute('list', datalist.id);

            honeyWrapper.appendChild(honeyLabel);
            honeyWrapper.appendChild(honeyInput);
            honeyWrapper.appendChild(datalist);
            honeyWrapper.appendChild(honeyHelper);

            select.addEventListener('change', function() {
                honeyInput.dataset.defaultApplied = '';
                honeyInput.dataset.defaultValue = '';
                populateHoneyVarietyOptions(honeyInput, datalist, this.value, component);
            });

            col.appendChild(honeyWrapper);

            // Populate initial options if default supplier preselected
            populateHoneyVarietyOptions(honeyInput, datalist, select.value, component);
        }

        container.appendChild(col);
    });

    wrapper.classList.remove('d-none');

    if (hintText) {
    hintText.textContent = details.hint || 'يرجى اختيار المورد المناسب لكل مادة وتحديد نوع العسل عند الحاجة.';
    }

    currentTemplateMode = 'advanced';
    modeInput.value = 'advanced';
}

// تحميل بيانات الإنتاج للتعديل
function editProduction(id) {
    const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
    const url = baseUrl + '/dashboard/production.php?page=production&ajax=1&id=' + id;
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById('edit_production_id').value = data.production.id;
                document.getElementById('edit_product_id').value = data.production.product_id;
                document.getElementById('edit_quantity').value = data.production.quantity;
                document.getElementById('edit_production_date').value = data.production.date;
                document.getElementById('edit_materials_used').value = data.production.materials_used || '';
                document.getElementById('edit_notes').value = data.production.notes || '';
                if (document.getElementById('edit_status')) {
                    document.getElementById('edit_status').value = data.production.status || 'pending';
                }
                
                const modal = new bootstrap.Modal(document.getElementById('editProductionModal'));
                modal.show();
            } else {
                alert(data.message || 'حدث خطأ في تحميل البيانات');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في تحميل البيانات: ' + error.message);
        });
}

// حذف الإنتاج
function deleteProduction(id) {
    if (confirm('<?php echo isset($lang['confirm_delete']) ? $lang['confirm_delete'] : 'هل أنت متأكد من حذف هذا السجل؟'; ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_production">
            <input type="hidden" name="production_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// عرض تفاصيل الإنتاج
function viewProduction(id) {
    // يمكن إضافة modal لعرض التفاصيل الكاملة
    alert('عرض تفاصيل الإنتاج #' + id);
}

// فتح modal إنشاء إنتاج من قالب
function openCreateFromTemplateModal(element) {
    const templateId = element.getAttribute('data-template-id');
    const templateName = element.getAttribute('data-template-name');
    const templateType = element.getAttribute('data-template-type') || 'legacy';
    
    document.getElementById('template_id').value = templateId;
    document.getElementById('template_product_name').value = templateName;
    document.getElementById('template_type').value = templateType;
    
    // إعادة تعيين القيم التلقائية
    document.querySelector('textarea[name="batch_notes"]').value = '';

    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');

    if (container) {
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <i class="bi bi-hourglass-split me-2"></i>
                    جاري تحميل بيانات المواد...
                </div>
            </div>
        `;
    }
    if (wrapper) {
        wrapper.classList.add('d-none');
    }
    currentTemplateMode = 'advanced';
    if (modeInput) {
        modeInput.value = 'advanced';
    }

    const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
    fetch(baseUrl + '/dashboard/production.php?page=production&ajax=template_details&template_id=' + templateId + '&template_type=' + encodeURIComponent(templateType))
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Network error')))
        .then(data => {
            if (data && data.success) {
                renderTemplateSuppliers(data);
            } else {
                if (container) {
                    container.innerHTML = `
                        <div class="col-12">
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لم يتم العثور على مواد لهذا القالب. يرجى مراجعة إعدادات القالب.
                            </div>
                        </div>
                    `;
                }
            }
        })
        .catch(error => {
            console.error('Error loading template details:', error);
            if (container) {
                container.innerHTML = `
                    <div class="col-12">
                        <div class="alert alert-danger mb-0">
                            <i class="bi bi-x-circle me-2"></i>
                            تعذّر تحميل بيانات القالب: ${error.message}
                        </div>
                    </div>
                `;
            }
        })
        .finally(() => {
            const modal = new bootstrap.Modal(document.getElementById('createFromTemplateModal'));
            modal.show();
        });
}

// إضافة معالج للنموذج للتحقق من الحقول المطلوبة
document.getElementById('createFromTemplateForm')?.addEventListener('submit', function(e) {
    const quantity = document.querySelector('input[name="quantity"]').value;

    const supplierSelects = document.querySelectorAll('#templateSuppliersContainer select[data-role="component-supplier"]');

    if (supplierSelects.length === 0) {
            e.preventDefault();
        alert('لا توجد مواد مرتبطة بالقالب، يرجى مراجعة القالب قبل إنشاء التشغيلة.');
            return false;
        }

        for (let select of supplierSelects) {
            if (!select.value) {
                e.preventDefault();
                alert('يرجى اختيار المورد لكل مادة قبل المتابعة');
                select.focus();
                return false;
            }
    }

    const honeyVarietyInputs = document.querySelectorAll('#templateSuppliersContainer input[data-role="honey-variety-input"]');
    for (let input of honeyVarietyInputs) {
        if (input.disabled) {
            e.preventDefault();
            alert('يرجى اختيار مورد العسل قبل تحديد نوعه');
            input.focus();
            return false;
        }
        if (!input.value || !input.value.trim()) {
            e.preventDefault();
            alert('يرجى إدخال نوع العسل لكل مورد مختار');
            input.focus();
            return false;
        }
    }

    // التحقق من الكمية
    if (!quantity || parseInt(quantity) <= 0) {
        e.preventDefault();
        alert('يرجى إدخال كمية صحيحة أكبر من الصفر');
        document.querySelector('input[name="quantity"]').focus();
        return false;
    }
});

document.getElementById('createFromTemplateModal')?.addEventListener('shown.bs.modal', function() {
    const modalBody = document.querySelector('#createFromTemplateModal .production-template-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
        modalBody.style.overflowY = 'auto';
    }
});

document.getElementById('createFromTemplateModal')?.addEventListener('hidden.bs.modal', function() {
    const modalBody = document.querySelector('#createFromTemplateModal .production-template-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
        modalBody.style.overflowY = '';
    }
});

// طباعة الباركودات
function printBarcodes() {
    const batchNumbers = window.batchNumbersToPrint || [];
    const printQuantity = parseInt(document.getElementById('barcode_print_quantity').value) || 1;
    
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيلة للطباعة');
        return;
    }
    
    // طباعة الباركودات - كل باركود يحتوي على نفس رقم التشغيلة
    // الكمية المطلوبة للطباعة
    const batchNumber = batchNumbers[0]; // كل الباركودات لها نفس الرقم
    const printUrl = 'print_barcode.php?batch=' + encodeURIComponent(batchNumber) + '&quantity=' + printQuantity + '&print=1';
    
    window.open(printUrl, '_blank');
}

// إدارة المواد الخام في modal إنشاء القالب
let rawMaterialIndex = 0;

function addRawMaterial() {
    const container = document.getElementById('rawMaterialsContainer');
    const materialHtml = `
        <div class="raw-material-item mb-2 border p-2 rounded">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">اسم المادة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][name]" 
                           placeholder="مثل: مكسرات، لوز" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">الكمية <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][quantity]" 
                           step="0.001" min="0.001" placeholder="0.000" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">الوحدة</label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][unit]" 
                           value="جرام" placeholder="جرام">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeRawMaterial(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', materialHtml);
    rawMaterialIndex++;
}

function removeRawMaterial(btn) {
    btn.closest('.raw-material-item').remove();
}

// التحقق من صحة النموذج قبل الإرسال
document.getElementById('createTemplateForm')?.addEventListener('submit', function(e) {
    // التحقق من اختيار أداة تعبئة واحدة على الأقل
    const packagingCheckboxes = document.querySelectorAll('input[name="packaging_ids[]"]:checked');
    if (packagingCheckboxes.length === 0) {
        e.preventDefault();
        alert('يرجى اختيار أداة تعبئة واحدة على الأقل');
        const firstCheckbox = document.querySelector('input[name="packaging_ids[]"]');
        if (firstCheckbox) {
            firstCheckbox.focus();
        }
        return false;
    }
    
    // التحقق من صحة كمية العسل
    const honeyQuantity = parseFloat(document.querySelector('input[name="honey_quantity"]').value);
    if (!honeyQuantity || honeyQuantity <= 0) {
        e.preventDefault();
        alert('يرجى إدخال كمية عسل صحيحة أكبر من الصفر');
        return false;
    }
    
    // التحقق من اسم المنتج
    const productName = document.querySelector('input[name="product_name"]').value.trim();
    if (!productName) {
        e.preventDefault();
        alert('يرجى إدخال اسم المنتج');
        return false;
    }
});

<?php
// عرض modal الطباعة إذا تم إنشاء إنتاج من قالب
if (isset($_GET['show_barcode_modal']) && isset($_SESSION['created_batch_numbers'])) {
    $batchNumbers = $_SESSION['created_batch_numbers'];
    $productName = $_SESSION['created_batch_product_name'] ?? '';
    $quantity = $_SESSION['created_batch_quantity'] ?? count($batchNumbers);
    
    // تنظيف session
    unset($_SESSION['created_batch_numbers']);
    unset($_SESSION['created_batch_product_name']);
    unset($_SESSION['created_batch_quantity']);
    
    echo "
    <script>
    window.batchNumbersToPrint = " . json_encode($batchNumbers) . ";
    
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('barcode_product_name').value = " . json_encode($productName) . ";
        document.getElementById('barcode_quantity').textContent = " . $quantity . ";
        document.getElementById('barcode_print_quantity').value = " . $quantity . ";
        
        // عرض رقم التشغيلة (كل الباركودات لها نفس الرقم)
        const batchNumber = " . json_encode($batchNumbers[0] ?? '') . ";
        let batchListHtml = '<div class=\"alert alert-info mb-0\">';
        batchListHtml += '<i class=\"bi bi-info-circle me-2\"></i>';
        batchListHtml += '<strong>رقم التشغيلة:</strong> ' + batchNumber + '<br>';
        batchListHtml += '<small>سيتم طباعة نفس رقم التشغيلة بعدد ' + " . $quantity . " . ' باركود</small>';
        batchListHtml += '</div>';
        document.getElementById('batch_numbers_list').innerHTML = batchListHtml;
        
        const modal = new bootstrap.Modal(document.getElementById('printBarcodesModal'));
        modal.show();
    });
    </script>
    ";
}
?>
</script>

<style>
.template-card {
    min-height: 180px;
}

.template-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
    border-left-width: 5px !important;
}

@media (max-width: 768px) {
    .template-card {
        margin-bottom: 0.75rem;
        min-height: 160px;
    }
}

@media (min-width: 1400px) {
    .template-card {
        min-height: 200px;
    }
}
</style>

<?php
// معالجة AJAX لتحميل بيانات الإنتاج
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['id'])) {
    $productionId = intval($_GET['id']);
    $production = $db->queryOne(
        "SELECT p.*, pr.name as product_name FROM production p 
         LEFT JOIN products pr ON p.product_id = pr.id 
         WHERE p.id = ?",
        [$productionId]
    );
    
    if ($production) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'production' => [
                'id' => $production['id'],
                'product_id' => $production['product_id'],
                'quantity' => $production['quantity'],
                'date' => $production[$dateColumn] ?? $production['created_at'],
                'materials_used' => $production['materials_used'] ?? '',
                'notes' => $production['notes'] ?? '',
                'status' => $production['status'] ?? 'pending'
            ]
        ]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على السجل']);
        exit;
    }
}
?>

<script>
// منع تكرار الإرسال - إضافة توكن فريد لكل نموذج
document.addEventListener('DOMContentLoaded', function() {
    // البحث عن جميع النماذج في الصفحة
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    
    forms.forEach(function(form) {
        // تحقق من عدم وجود توكن موجود بالفعل
        if (!form.querySelector('input[name="submit_token"]')) {
            // إنشاء توكن فريد
            const token = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            // إنشاء حقل مخفي للتوكن
            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'submit_token';
            tokenInput.value = token;
            
            // إضافة التوكن للنموذج
            form.appendChild(tokenInput);
        }
        
        // منع إعادة الإرسال عند الضغط على زر الإرسال
        form.addEventListener('submit', function(e) {
            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton) {
                // تعطيل الزر فوراً
                submitButton.disabled = true;
                submitButton.style.opacity = '0.6';
                submitButton.style.cursor = 'not-allowed';
                
                // إضافة نص "جاري المعالجة..."
                const originalText = submitButton.innerHTML || submitButton.value;
                if (submitButton.tagName === 'BUTTON') {
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> جاري المعالجة...';
                } else {
                    submitButton.value = 'جاري المعالجة...';
                }
                
                // إعادة تفعيل الزر بعد 3 ثواني (في حالة فشل الإرسال)
                setTimeout(function() {
                    submitButton.disabled = false;
                    submitButton.style.opacity = '1';
                    submitButton.style.cursor = 'pointer';
                    if (submitButton.tagName === 'BUTTON') {
                        submitButton.innerHTML = originalText;
                    } else {
                        submitButton.value = originalText;
                    }
                    
                    // تحديث التوكن
                    const newToken = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const tokenInput = form.querySelector('input[name="submit_token"]');
                    if (tokenInput) {
                        tokenInput.value = newToken;
                    }
                }, 3000);
            }
        });
    });
    
    // منع إعادة الإرسال عند الضغط على F5 أو Refresh
    if (performance.navigation.type === 1) {
        // الصفحة تم تحديثها (Refresh)
        // إزالة أي رسائل خطأ قد تكون ناتجة عن إعادة الإرسال
        console.log('تم اكتشاف إعادة تحميل الصفحة - تم منع إعادة الإرسال');
    }
});

// تحذير عند محاولة إعادة الإرسال
window.addEventListener('beforeunload', function(e) {
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    let formModified = false;
    
    forms.forEach(function(form) {
        // تحقق إذا تم تعديل أي حقل
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            if (input.value !== input.defaultValue) {
                formModified = true;
            }
        });
    });
    
    // لا تعرض تحذير إذا لم يتم تعديل أي شيء
    if (!formModified) {
        return undefined;
    }
});
</script>

