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
            // البحث في جدول المنتجات أولاً
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
                // البحث في جدول مواد التعبئة إذا كان موجوداً
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
        
        // البحث عن المادة في جدول المنتجات
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
        
        // البحث عن العسل في جدول honey_stock (العسل المصفى فقط)
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
            // البحث في جدول المنتجات كبديل
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

// إنشاء جدول batch_numbers إذا لم يكن موجودًا مسبقًا
$hasBatchNumbersTable = false;
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
    
    // إضافة العمود all_suppliers إذا لم يكن موجودًا مسبقًا
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
    
    // إضافة العمود honey_variety إذا لم يكن موجودًا مسبقًا
    $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'honey_variety'");
    if (empty($honeyVarietyColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'Honey variety used' 
                AFTER `honey_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding honey_variety column: " . $e->getMessage());
        }
    }
    $hasBatchNumbersTable = true;
} catch (Exception $e) {
    error_log("Batch numbers table creation error: " . $e->getMessage());
    $hasBatchNumbersTable = false;
}

// استرجاع رسالة النجاح من الجلسة (بعد إعادة التوجيه)
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

// معالجة الطلبات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // منع تكرار الإرسال باستخدام رمز CSRF
    $submitToken = $_POST['submit_token'] ?? '';
    $sessionToken = $_SESSION['last_submit_token'] ?? '';
    
    if ($submitToken && $submitToken === $sessionToken) {
        // تم إرسال هذا النموذج بالفعل - تجاهل التكرار
        $error = 'تم معالجة هذا الطلب من قبل. يرجى عدم إعادة تحميل الصفحة بعد الإرسال.';
        error_log("Duplicate form submission detected: token={$submitToken}, action={$action}");
    } elseif ($action === 'add_production') {
        // حفظ الرمز لمنع الإرسال المتكرر
        $_SESSION['last_submit_token'] = $submitToken;
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = $_POST['unit'] ?? 'kg'; // كيلوغرام أو غرام
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        
        // تحويل الجرام إلى كيلوغرام إذا لزم الأمر
        if ($unit === 'g' || $unit === 'gram') {
            $quantity = $quantity / 1000; // تحويل من جرام إلى كيلوجرام
        }
        
        // تحديد معرف المستخدم المسؤول عن الإنتاج وفقًا للدور
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
            // بناء الاستعلام ديناميكيًا بحسب الأعمدة المتاحة
            $columns = ['product_id', 'quantity'];
            $values = [$productId, $quantity];
            $placeholders = ['?', '?'];
            
            // إضافة عمود التاريخ
            $columns[] = $dateColumn;
            $values[] = $productionDate;
            $placeholders[] = '?';
            
            // إضافة عمود المستخدم أو العامل إن وجد
            if ($userIdColumn) {
                $columns[] = $userIdColumn;
                $values[] = $selectedUserId;
                $placeholders[] = '?';
            }
            
            // إضافة بيانات المواد المستخدمة إن وُجدت
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
            
            // إضافة الحالة (افتراضيًا pending)
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
                
                // منع التكرار باستخدام إعادة التوجيه
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
        $unit = $_POST['unit'] ?? 'kg'; // كيلوغرام أو غرام
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        
        // تحويل الجرام إلى كيلوغرام إذا لزم الأمر
        if ($unit === 'g' || $unit === 'gram') {
            $quantity = $quantity / 1000; // تحويل الجرام إلى كيلوغرام
        }
        
        if ($productionId <= 0) {
            $error = 'معرف الإنتاج غير صحيح';
        } elseif ($productId <= 0) {
            $error = 'يجب اختيار المنتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة';
        } else {
            // بناء الاستعلام ديناميكيًا بحسب الأعمدة المتاحة
            $setParts = ['product_id = ?', 'quantity = ?'];
            $values = [$productId, $quantity];
            
            // تحديث عمود التاريخ
            $setParts[] = "$dateColumn = ?";
            $values[] = $productionDate;
            
            // تحديث بيانات المواد المستخدمة إذا توفرت
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
                
                // منع التكرار باستخدام إعادة التوجيه
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
                // حذف بيانات الإنتاج المرتبطة أولًا
                $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);
                
                // حذف سجل الإنتاج
                $db->execute("DELETE FROM production WHERE id = ?", [$productionId]);
                
                logAudit($currentUser['id'], 'delete_production', 'production', $productionId, null, null);
                
                // منع التكرار عن طريق إعادة التوجيه
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

                $extraSupplierIdsInput = $_POST['extra_supplier_ids'] ?? [];
                $extraSupplierIds = [];
                if (is_array($extraSupplierIdsInput)) {
                    foreach ($extraSupplierIdsInput as $value) {
                        $supplierIdValue = intval($value);
                        if ($supplierIdValue > 0) {
                            $extraSupplierIds[$supplierIdValue] = true;
                        }
                    }
                    $extraSupplierIds = array_keys($extraSupplierIds);
                }

                if (empty($materialSuppliers)) {
                    throw new Exception('يرجى اختيار المورد المحاسب لهذه المادة قبل إنشاء التشغيلة.');
                }

                $templateMode = $_POST['template_mode'] ?? 'advanced';
                if ($templateMode !== 'advanced') {
                    $templateMode = 'advanced';
                }
                $templateType = trim($_POST['template_type'] ?? 'legacy');

                // محاولة استرجاع القالب الموحد إن وُجد
                $unifiedTemplate = $db->queryOne(
                    "SELECT * FROM unified_product_templates WHERE id = ? AND status = 'active'",
                    [$templateId]
                );
                
                $isUnifiedTemplate = !empty($unifiedTemplate);
                
                if ($isUnifiedTemplate) {
                    // القالب الموحد
                    $template = $unifiedTemplate;
                    
                    // التحقق من توافر المواد الخام
                    $rawMaterials = $db->query(
                        "SELECT * FROM template_raw_materials WHERE template_id = ?",
                        [$templateId]
                    );
                    
                    if (empty($rawMaterials)) {
                        throw new Exception('القالب لا يحتوي على مواد خام.');
                    }
                    
                    $insufficientMaterials = [];
                    foreach ($rawMaterials as $material) {
                        $quantityColumnValue = isset($material['quantity']) ? $material['quantity'] : ($material['quantity_per_unit'] ?? 0);
                        $requiredQty = floatval($quantityColumnValue) * $quantity;
                        $supplierIdForCheck = $material['supplier_id'] ?? null;
                        
                        // التحقق من توافر المادة حسب نوعها
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
                                $available = ['total' => PHP_FLOAT_MAX]; // مواد أخرى نعتبرها متاحة
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
                    
                    // التحقق من توافر أدوات التعبئة
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
                    // القالب التقليدي
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
                    
                    // التحقق من توافر المكونات اللازمة للإنتاج
                    $materialsCheck = checkMaterialsAvailability($db, $templateId, $quantity);
                    if (!$materialsCheck['available']) {
                        throw new Exception('المكونات غير متوفرة: ' . $materialsCheck['message']);
                    }
                }
                
                // إنشاء المنتج إذا لم يكن موجودًا
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
                
                // تجميع أدوات التعبئة والموردين المرتبطين بالقالب
                $packagingIds = [];
                $allSuppliers = []; // جميع الموردين المستخدمين في هذه التشغيلة
                $materialsConsumption = [
                    'raw' => [],
                    'packaging' => []
                ];
                
                if ($isUnifiedTemplate) {
                    // القالب الموحد - استرجاع أدوات التعبئة
                    $hasTemplatePackagingNameColumn = productionColumnExists('template_packaging', 'packaging_name');
                    if ($hasTemplatePackagingNameColumn) {
                        $packagingNameExpression = 'tp.packaging_name AS packaging_name';
                    } else {
                        $packagingNameExpression = "COALESCE(pm.name, CONCAT('أداة تعبئة #', tp.packaging_material_id)) AS packaging_name";
                    }
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
                        // الموردون المحددون داخل القالب المتقدم
                        foreach ($packagingItems as $pkg) {
                            $pkgKey = 'pack_' . ($pkg['packaging_material_id'] ?? $pkg['id']);
                            $selectedSupplierId = $materialSuppliers[$pkgKey] ?? 0;
                            if (empty($selectedSupplierId)) {
                                throw new Exception('يرجى اختيار المورد المناسب لأداة التعبئة قبل إنشاء التشغيلة.');
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
                                    'product_id' => $packagingProductId,
                                    'supplier_id' => $selectedSupplierId
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
                                throw new Exception('يرجى اختيار المورد المناسب لهذه المادة الخام قبل إنشاء التشغيلة.');
                            }
                            
                            $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                            if (!$supplierInfo) {
                                throw new Exception('مورد غير صالح لهذه المادة الخام: ' . ($materialRow['material_name'] ?? 'غير معروف'));
                            }
                            
                            $materialType = $materialRow['material_type'] ?? '';
                            $selectedHoneyVariety = $materialHoneyVarieties[$rawKey] ?? '';
                            if (in_array($materialType, ['honey_raw', 'honey_filtered'], true) && $selectedHoneyVariety === '') {
                                throw new Exception('يرجى تحديد نوع العسل لهذه المادة الخام: ' . ($materialRow['material_name'] ?? 'عسل'));
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
                        // وضعFallback للقوالب القديمة حيث تُخزن بيانات الموردين داخل القالب
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
                    // القالب القديم لأصناف المنتجات البسيطة (عسل، زيت، شمع، مشتقات)
                    $legacyHasPackagingNameColumn = productionColumnExists('product_template_packaging', 'packaging_name');
                    $legacyPackagingNameExpression = $legacyHasPackagingNameColumn
                        ? 'ptp.packaging_name AS packaging_name'
                        : "COALESCE(pm.name, CONCAT('أداة تعبئة #', ptp.packaging_material_id)) AS packaging_name";
                    $packagingMaterials = $db->query(
                        "SELECT ptp.id, ptp.packaging_material_id, {$legacyPackagingNameExpression}, ptp.quantity_per_unit,
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
                        throw new Exception('يرجى اختيار المورد المناسب لأداة التعبئة المستخدمة في القالب.');
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
                                'product_id' => $legacyPackagingProductId,
                                'supplier_id' => $selectedSupplierId
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
                                throw new Exception('يرجى اختيار مورد المشتقات.');
                            }
                            $derivativeSupplier = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$derivativeSupplierId]);
                            if (!$derivativeSupplier) {
                                throw new Exception('مورد المشتقات غير صالح.');
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
                            throw new Exception('يرجى اختيار المورد لهذه المادة الخام: ' . ($legacyRaw['material_name'] ?? 'مادة خام'));
                        }

                        $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                        if (!$supplierInfo) {
                            throw new Exception('مورد غير صالح لهذه المادة الخام: ' . ($legacyRaw['material_name'] ?? 'غير معروف'));
                        }

                        $allSuppliers[] = [
                            'id' => $supplierInfo['id'],
                            'name' => $supplierInfo['name'],
                            'type' => $supplierInfo['type'],
                            'material' => $legacyRaw['material_name'] ?? 'مادة خام'
                        ];
                    }
                }
                
                // التحقق من توافر نوع العسل لدى المورد المحدد
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
                            throw new Exception('المورد المحدد لا تتوفر لديه كمية كافية من نوع العسل: ' . $varietyLabel);
                        }
                        
                        $availableHoney = (float)($supplierHoney['available_quantity'] ?? 0);
                        if ($availableHoney < $requiredHoneyQuantity) {
                            $varietyLabel = $supplierHoney['honey_variety'] ?? $rawItem['honey_variety'] ?? ($rawItem['material_name'] ?: 'العسل المطلوب');
                            throw new Exception(sprintf(
                                'الكمية المتاحة لدى المورد %s غير كافية. مطلوب %.2f كجم، متوفر %.2f كجم.',
                                $varietyLabel,
                                $requiredHoneyQuantity,
                                $availableHoney
                            ));
                        }
                    }
                }
                
                // تحديد معرف المستخدم
                $selectedUserId = $currentUser['role'] === 'production' ? $currentUser['id'] : intval($_POST['user_id'] ?? $currentUser['id']);
                
                // 3. جمع عمال الإنتاج الحاضرين داخل الموقع
                $workersList = [];
                $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                if (!empty($attendanceTableCheck)) {
                    // استرجاع عمال الإنتاج الذين سجلوا الحضور اليوم
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
                
                // إذا لم يوجد عمال حاضرين، استخدم المستخدم الحالي فقط
                if (empty($workersList)) {
                    $workersList = [$selectedUserId];
                }
                
                $extraSuppliersDetails = [];
                if (!empty($extraSupplierIds)) {
                    $supplierPlaceholders = implode(',', array_fill(0, count($extraSupplierIds), '?'));
                    $suppliersResult = $db->query(
                        "SELECT id, name, type FROM suppliers WHERE id IN ($supplierPlaceholders)",
                        $extraSupplierIds
                    );
                    $suppliersById = [];
                    foreach ($suppliersResult as $supplierRow) {
                        $suppliersById[(int)($supplierRow['id'] ?? 0)] = $supplierRow;
                    }
                    foreach ($extraSupplierIds as $selectedSupplierId) {
                        if (isset($suppliersById[$selectedSupplierId])) {
                            $extraSuppliersDetails[] = $suppliersById[$selectedSupplierId];
                        }
                    }
                }

                if (!empty($extraSuppliersDetails)) {
                    $existingSupplierIds = [];
                    foreach ($allSuppliers as $supplierEntry) {
                        if (isset($supplierEntry['id']) && $supplierEntry['id']) {
                            $existingSupplierIds[(int)$supplierEntry['id']] = true;
                        }
                    }

                    foreach ($extraSuppliersDetails as $supplierRow) {
                        $supplierIdValue = (int)($supplierRow['id'] ?? 0);
                        if ($supplierIdValue > 0 && isset($existingSupplierIds[$supplierIdValue])) {
                            continue;
                        }
                        if ($supplierIdValue > 0) {
                            $existingSupplierIds[$supplierIdValue] = true;
                        }
                        $allSuppliers[] = [
                            'id' => $supplierIdValue > 0 ? $supplierIdValue : null,
                            'name' => $supplierRow['name'] ?? 'مورد إضافي',
                            'type' => $supplierRow['type'] ?? null,
                            'material' => 'مورد إضافي (اختياري)',
                            'context' => 'manual_extra_supplier'
                        ];
                    }
                }

                // 4. الملاحظات: توليد ملاحظات تلقائية تذكر الموردين
                $batchNotes = '';
                $notesParts = ['تم إنشاؤه من قالب: ' . $template['product_name']];
                
                // إضافة جميع الموردين إلى الملاحظات
                if (!empty($allSuppliers)) {
                    $supplierNames = [];
                    foreach ($allSuppliers as $supplier) {
                        $supplierNames[] = $supplier['name'] . ' (' . $supplier['material'] . ')';
                    }
                    $notesParts[] = 'الموردون: ' . implode(', ', $supplierNames);
                }

                if (!empty($extraSuppliersDetails)) {
                    $manualSupplierLabels = [];
                    foreach ($extraSuppliersDetails as $supplierRow) {
                        $label = $supplierRow['name'] ?? '';
                        $typeLabel = $supplierRow['type'] ?? '';
                        if ($label === '') {
                            $label = 'مورد #' . ($supplierRow['id'] ?? '');
                        }
                        if ($typeLabel !== '') {
                            $label .= ' (' . $typeLabel . ')';
                        }
                        $manualSupplierLabels[] = $label;
                    }
                    if (!empty($manualSupplierLabels)) {
                        $notesParts[] = 'موردون إضافيون: ' . implode(', ', $manualSupplierLabels);
                    }
                }

                $batchNotes = implode(' | ', $notesParts);
                
                // إنشاء سجل إنتاج واحد للتشغيلة
                $columns = ['product_id', 'quantity'];
                $values = [$productId, $quantity]; // الكمية النهائية
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
                    $honeySupplierId, // مورد العسل (إن توفر)
                    $packagingIds,
                    $packagingSupplierId,
                    $workersList, // العمال الحاضرون (قائمة)
                    $quantity, // الكمية النهائية
                    null, // expiry_date
                    $batchNotes, // الملاحظات (إن وُجدت)
                    $currentUser['id'],
                    $allSuppliers, // جميع الموردين مع المواد
                    $honeyVariety ?? null, // نوع العسل المستخدم
                    $templateId
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
                                // لا يوجد تعريف واضح للمورد في القوالب القديمة؛ يتم تجاوز الخصم تلقائيًا
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
                
                // إنشاء أرقام باركود بعدد الكمية المنتجة
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
                
                // حفظ أرقام التشغيلة في الجلسة لعرضها في نافذة الطباعة
                $_SESSION['created_batch_numbers'] = $batchNumbersToPrint; // أرقام باركود بعدد الكمية
                $_SESSION['created_batch_product_name'] = $template['product_name'];
                $_SESSION['created_batch_quantity'] = $quantity;
                
                // منع التكرار باستخدام إعادة التوجيه
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
    // منع إنشاء قوالب المنتجات من خارج صفحة مخزن الخامات
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
// عدد السجلات في كل صفحة
$perPage = 5; // خمسة عناصر في كل صفحة
$offset = ($pageNum - 1) * $perPage;

// معايير البحث والتصفية
$search = $_GET['search'] ?? '';
$productId = $_GET['product_id'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// بناء شروط الاستعلام
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

// حساب إجمالي السجلات
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

// جلب بيانات التشغيلات (أرقام الدُفعات)
$batchJoinSelect = $hasBatchNumbersTable ? ', bn.batch_number as batch_number' : ', NULL as batch_number';
$batchJoinClause = '';

if ($hasBatchNumbersTable) {
    $batchJoinClause = "
            LEFT JOIN (
                SELECT b1.production_id, b1.batch_number
                FROM batch_numbers b1
                WHERE b1.production_id IS NOT NULL
                  AND b1.id = (
                      SELECT MAX(b2.id)
                      FROM batch_numbers b2
                      WHERE b2.production_id = b1.production_id
                  )
            ) bn ON bn.production_id = p.id";
}

if ($userIdColumn) {
    $sql = "SELECT p.*, 
                   pr.name as product_name, 
                   pr.category as product_category,
                   u.full_name as worker_name,
                   u.username as worker_username
                   $batchJoinSelect
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id
            LEFT JOIN users u ON p.{$userIdColumn} = u.id
            $batchJoinClause
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
} else {
    $sql = "SELECT p.*, 
                   pr.name as product_name, 
                   pr.category as product_category,
                   'غير محدد' as worker_name,
                   'غير محدد' as worker_username
                   $batchJoinSelect
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id
            $batchJoinClause
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
}

$params[] = $perPage;
$params[] = $offset;

$productions = $db->query($sql, $params);

// جلب المنتجات النشطة لعرضها في القوائم
$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' ORDER BY name");
$workers = $db->query("SELECT id, username, full_name FROM users WHERE role = 'production' AND status = 'active' ORDER BY username");

// جلب قائمة الموردين
$suppliers = [];
$suppliersTableCheck = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
if (!empty($suppliersTableCheck)) {
    $suppliers = $db->query("SELECT id, name, type FROM suppliers WHERE status = 'active' ORDER BY name");
}

// إنشاء جداول القوالب إذا لم تكن موجودة
try {
    $templatesTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
    if (empty($templatesTableCheck)) {
        // إنشاء جدول product_templates
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
              `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Honey quantity in grams',
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
        // إضافة عمود honey_quantity إذا لم يكن موجودًا مسبقًا
        $honeyColumnCheck = $db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'honey_quantity'");
        if (empty($honeyColumnCheck)) {
            try {
                $db->execute("
                    ALTER TABLE `product_templates` 
                    ADD COLUMN `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Honey quantity in grams' 
                    AFTER `product_name`
                ");
                error_log("Added honey_quantity column to product_templates table");
            } catch (Exception $e) {
                error_log("Error adding honey_quantity column: " . $e->getMessage());
            }
        }
    }
    
    // إ?شاء جد^" product_template_packaging
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
              `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة الخام',
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية المادة الخام بالجرام',
              `unit` varchar(50) DEFAULT 'جرام',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        
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
                  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة الخام',
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية المادة الخام بالجرام',
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

// جلب قوالب المنتجات عبر جميع الأقسام
$templates = [];

// 0. القوالب الموحدة الحديثة (متعددة المواد)
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    // التحقق من وجود جدول المواد الخام وإضافة عمود honey_variety عند الحاجة
    $templateRawMaterialsCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
    $hasHoneyVariety = false;
    
    if (!empty($templateRawMaterialsCheck)) {
        $honeyVarietyCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
        $hasHoneyVariety = !empty($honeyVarietyCheck);
        
        // إضافة العمود إذا لم يكن موجودًا
        if (!$hasHoneyVariety) {
            try {
                $db->execute("
                    ALTER TABLE `template_raw_materials` 
                    ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'Honey variety used' 
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
    
    $templatePackagingCheck = $db->queryOne("SHOW TABLES LIKE 'template_packaging'");

    foreach ($unifiedTemplates as &$template) {
        // جلب المواد الخام المرتبطة بالقالب
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
            // ترجمة اسم المادة إلى العربية إن كانت قيمة معرفية
            $materialNameArabic = $material['material_name'];
            
            // قاموس للترجمات الشائعة
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
            
            // تطبيق الترجمة إذا كانت متاحة
            if (isset($materialTranslations[$materialNameArabic])) {
                $materialNameArabic = $materialTranslations[$materialNameArabic];
            }
            
            $materialDisplay = $materialNameArabic;
            
            // إضافة نوع العسل إن وُجد (فقط إذا توافر العمود)
            if ($hasHoneyVariety 
                && ($material['material_type'] === 'honey_raw' || $material['material_type'] === 'honey_filtered') 
                && !empty($material['honey_variety'])) {
                $materialDisplay .= ' (' . $material['honey_variety'] . ')';
            }
            
            $template['material_details'][] = [
                'type' => $materialDisplay,
                'quantity' => $material['quantity'],
                'unit' => $material['unit'],
                'honey_variety' => $material['honey_variety'] ?? null,
                'material_type' => $material['material_type']
            ];
        }

        // جلب أدوات التعبئة المرتبطة بالقالب
        $packagingDetails = [];
        if (!empty($templatePackagingCheck)) {
            try {
                $hasTemplatePackagingNameColumn = productionColumnExists('template_packaging', 'packaging_name');
                $packagingNameSelect = $hasTemplatePackagingNameColumn
                    ? 'tp.packaging_name'
                    : "COALESCE(pm.name, CONCAT('أداة تعبئة #', tp.packaging_material_id))";

                $packagingSql = "
                    SELECT tp.packaging_material_id, tp.quantity_per_unit,
                           {$packagingNameSelect} AS packaging_name,
                           pm.unit AS packaging_unit
                    FROM template_packaging tp
                    LEFT JOIN packaging_materials pm ON tp.packaging_material_id = pm.id
                    WHERE tp.template_id = ?
                ";

                try {
                    $packagingItems = $db->query($packagingSql, [$template['id']]);

                    foreach ($packagingItems as $item) {
                        $quantity = isset($item['quantity_per_unit']) ? (float)$item['quantity_per_unit'] : 0.0;
                        if ($quantity <= 0) {
                            continue;
                        }

                        $packagingDetails[] = [
                            'name' => $item['packaging_name'] ?? 'مادة تعبئة',
                            'quantity_per_unit' => $quantity,
                            'unit' => $item['packaging_unit'] ?? 'وحدة'
                        ];
                    }
                } catch (Exception $innerError) {
                    // في حال فشل الاستعلام الأساسي بسبب عمود مفقود، كرر بدون الأعمدة الاختيارية
                    $fallbackItems = $db->query(
                        "SELECT tp.packaging_material_id, tp.quantity_per_unit,
                                COALESCE(pm.name, CONCAT('أداة تعبئة #', tp.packaging_material_id)) AS packaging_name,
                                pm.unit AS packaging_unit
                         FROM template_packaging tp
                         LEFT JOIN packaging_materials pm ON tp.packaging_material_id = pm.id
                         WHERE tp.template_id = ?",
                        [$template['id']]
                    );

                    foreach ($fallbackItems as $item) {
                        $quantity = isset($item['quantity_per_unit']) ? (float)$item['quantity_per_unit'] : 0.0;
                        if ($quantity <= 0) {
                            continue;
                        }

                        $packagingDetails[] = [
                            'name' => $item['packaging_name'] ?? 'مادة تعبئة',
                            'quantity_per_unit' => $quantity,
                            'unit' => $item['packaging_unit'] ?? 'وحدة'
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Fetching template packaging failed for template {$template['id']}: " . $e->getMessage());
            }
        }

        $template['packaging_details'] = $packagingDetails;
        $template['packaging_count'] = count($packagingDetails);
    }
    
    // تصفية القوالب: تجاهل القوالب التي لا تحتوي على مواد
    $unifiedTemplates = array_filter($unifiedTemplates, function($template) {
        return !empty($template['material_details']);
    });
    
    $templates = array_merge($templates, $unifiedTemplates);
}

// 1. قوالب العسل (القوالب التقليدية)
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

// جلب أدوات التعبئة المستخدمة في نافذة إنشاء القالب
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

$additionalSuppliersOptions = [];
try {
    $suppliersStatusColumnCheck = $db->queryOne("SHOW COLUMNS FROM suppliers LIKE 'status'");
    if (!empty($suppliersStatusColumnCheck)) {
        $additionalSuppliersOptions = $db->query(
            "SELECT id, name, type FROM suppliers WHERE status = 'active' ORDER BY name ASC"
        );
    } else {
        $additionalSuppliersOptions = $db->query(
            "SELECT id, name, type FROM suppliers ORDER BY name ASC"
        );
    }
} catch (Exception $e) {
    error_log('Failed to load suppliers list: ' . $e->getMessage());
    $additionalSuppliersOptions = [];
}

$additionalSuppliersSelectSize = max(4, min(10, count($additionalSuppliersOptions)));

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

<!-- قسم قوالب المنتجات -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>قوالب المنتجات - إنشاء إنتاج من قالب</h5>
        <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-light btn-sm">
            <i class="bi bi-plus-circle me-2"></i>إدارة القوالب في مخزن الخامات
        </a>
    </div>
    <?php if (!empty($templates)): ?>
    <div class="card-body template-grid">
        <?php foreach ($templates as $template): ?>
            <?php 
            $templateTypeLabels = [
                'unified' => 'متعدد المواد',
                'honey' => 'عسل',
                'olive_oil' => 'زيت زيتون',
                'beeswax' => 'شمع عسل',
                'derivatives' => 'مشتقات'
            ];
            $typeLabel = $templateTypeLabels[$template['template_type']] ?? 'غير محدد';
            $typeAccents = [
                'unified' => ['#0f172a', '#0f172a22'],
                'honey' => ['#f59e0b', '#f59e0b22'],
                'olive_oil' => ['#16a34a', '#16a34a22'],
                'beeswax' => ['#2563eb', '#2563eb22'],
                'derivatives' => ['#7c3aed', '#7c3aed22']
            ];
            $accentPair = $typeAccents[$template['template_type']] ?? ['#334155', '#33415522'];
            $materialDetails = $template['material_details'] ?? [];
            $materialsPreview = array_slice($materialDetails, 0, 3);
            $hasMoreMaterials = count($materialDetails) > 3;
            $packagingCount = (int)($template['packaging_count'] ?? 0);
            $rawCount = count($materialDetails);
            $productsCount = (int)($template['products_count'] ?? 1);
            ?>
            <div class="template-card-modern"
                 style="--template-accent: <?php echo $accentPair[0]; ?>; --template-accent-light: <?php echo $accentPair[1]; ?>;"
                 data-template-id="<?php echo $template['id']; ?>"
                 data-template-name="<?php echo htmlspecialchars($template['product_name']); ?>"
                 data-template-type="<?php echo htmlspecialchars($template['template_type'] ?? 'legacy'); ?>"
                 onclick="openCreateFromTemplateModal(this)">

                <div class="template-card-header">
                    <div>
                        <span class="template-type-pill">
                            <i class="bi bi-droplet-half me-1"></i><?php echo $typeLabel; ?>
                        </span>
                        <h6 class="template-title mt-2"><?php echo htmlspecialchars($template['product_name']); ?></h6>
                    </div>
                    <div class="template-products-count">
                        <i class="bi bi-box me-1"></i>
                        <?php echo $productsCount; ?>
                        <span>منتج</span>
                    </div>
                </div>

                <div class="template-metrics">
                    <div class="metric-item">
                        <span class="metric-label">أدوات التعبئة</span>
                        <span class="metric-value"><?php echo $packagingCount; ?></span>
                    </div>
                    <div class="metric-separator"></div>
                    <div class="metric-item">
                        <span class="metric-label">مواد خام</span>
                        <span class="metric-value"><?php echo $rawCount; ?></span>
                    </div>
                    <div class="metric-separator"></div>
                    <div class="metric-item">
                        <span class="metric-label">آخر تعديل</span>
                        <span class="metric-value">
                            <?php echo htmlspecialchars(date('Y/m/d', strtotime($template['updated_at'] ?? $template['created_at'] ?? 'now'))); ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($materialsPreview)): ?>
                <div class="template-materials">
                    <?php foreach ($materialsPreview as $material): ?>
                        <div class="material-row">
                            <div class="material-icon">
                                <i class="bi bi-drop"></i>
                            </div>
                            <div class="material-info">
                                <div class="material-name"><?php echo htmlspecialchars($material['type']); ?></div>
                                <div class="material-quantity">
                                    <?php echo number_format($material['quantity'], 2); ?>
                                    <span><?php echo htmlspecialchars($material['unit']); ?></span>
                                </div>
                            </div>
                            <?php if (!empty($material['honey_variety'])): ?>
                                <span class="material-tag"><?php echo htmlspecialchars($material['honey_variety']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($hasMoreMaterials): ?>
                        <div class="materials-more">+ <?php echo $rawCount - count($materialsPreview); ?> مواد إضافية</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="template-actions">
                    <span class="template-action-badge">
                        <i class="bi bi-lightning-charge me-2"></i>ابدأ الإنتاج الآن
                    </span>
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

<!-- جدول الإنتاج -->
<div class="card shadow-sm mt-4">
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
                        <th><?php echo isset($lang['batch_number']) ? $lang['batch_number'] : 'رقم التشغيلة'; ?></th>
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
                                <td><?php echo htmlspecialchars($prod['product_name'] ?? 'منتج غير محدد'); ?></td>
                                <td><?php echo number_format($prod['quantity'] ?? 0, 2); ?></td>
                                <td><?php echo htmlspecialchars($prod['worker_name'] ?? $prod['worker_username'] ?? 'مستخدم غير محدد'); ?></td>
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
                                    <?php if (!empty($prod['batch_number'])): ?>
                                        <span class="badge bg-secondary text-wrap" style="white-space: normal;"><?php echo htmlspecialchars($prod['batch_number']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">غير متوفر</span>
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
                <small>عرض <?php echo min($offset + 1, $totalProduction); ?> - <?php echo min($offset + $perPage, $totalProduction); ?> من أصل <?php echo $totalProduction; ?> سجل</small>
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>

</div>

</div>

<div id="productionReportsSection" class="d-none">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-day me-2"></i>ملخص اليوم الحالي</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($productionReportsToday['date_from'] ?? $productionReportsTodayDate); ?>
                        إلى
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
                        إلى
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
            <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة خلال الشهر</span>
        </div>
        <div class="card-body">
            <?php productionPageRenderConsumptionTable($productionReportsMonth['packaging']['items'] ?? []); ?>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-droplet-half me-2"></i>المواد الخام خلال الشهر</span>
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
    <div class="modal-dialog modal-xl modal-dialog-scrollable production-template-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
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
                    
                    <!-- ملخص المكونات في القالب -->
                    <div class="mb-3 section-block d-none" id="templateComponentsSummary">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-activity me-2"></i>ملخص المكونات
                        </h6>
                        <div class="template-summary-grid" id="templateComponentsSummaryGrid"></div>
                    </div>
                    
                    <!-- الموردون لكل مادة -->
                    <div class="mb-3 section-block d-none" id="templateSuppliersWrapper">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-truck me-2"></i>الموردون لكل مادة <span class="text-danger">*</span>
                        </h6>
                        <p class="text-muted small mb-3" id="templateSuppliersHint">يرجى اختيار المورد المناسب لكل مادة سيتم استخدامها في هذه التشغيلة.</p>
                        <div class="row g-3" id="templateSuppliersContainer"></div>
                    </div>

                    <!-- معلومات إضافية اختيارية -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-list-check me-2"></i>معلومات إضافية (اختياري)
                        </h6>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-bold d-flex align-items-center gap-2">
                                    <i class="bi bi-diagram-3 text-primary"></i>
                                    المواد المستخدمة في القالب
                                </label>
                                <div id="templateMaterialsInfo" class="template-materials-info">
                                    <div class="text-muted small">سيتم عرض المواد والمقادير الخاصة بالقالب بعد اختيار قالب الإنتاج.</div>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">موردون إضافيون</label>
                                <?php if (!empty($additionalSuppliersOptions)): ?>
                                    <select id="extraSuppliersSelect"
                                            name="extra_supplier_ids[]"
                                            class="form-select"
                                            multiple
                                            size="<?php echo (int)$additionalSuppliersSelectSize; ?>">
                                        <?php foreach ($additionalSuppliersOptions as $option): ?>
                                            <?php
                                            $supplierId = (int)($option['id'] ?? 0);
                                            $supplierName = trim((string)($option['name'] ?? ''));
                                            $supplierType = trim((string)($option['type'] ?? ''));
                                            if ($supplierName === '') {
                                                $supplierName = 'مورد #' . $supplierId;
                                            }
                                            $supplierLabel = $supplierName;
                                            if ($supplierType !== '') {
                                                $supplierLabel .= ' (' . $supplierType . ')';
                                            }
                                            ?>
                                            <option value="<?php echo $supplierId; ?>">
                                                <?php echo htmlspecialchars($supplierLabel, ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">استخدم التحديد المتعدد لتعيين أكثر من مورد مشارك.</small>
                                <?php else: ?>
                                    <div class="form-control-plaintext text-muted">لا توجد بيانات موردين إضافية متاحة.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- عمال الإنتاج الحاضرون -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-people me-2"></i>عمال الإنتاج الحاضرون</h6>
                        <?php
                        // جلب عمال الإنتاج الحاضرين داخل المنشأة
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
                                <strong>عمال الإنتاج الحاضرون حاليا:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($presentWorkersToday as $worker): ?>
                                        <li><?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <small class="text-muted">سيتم ربط التشغيلة تلقائياً بجميع عمال الإنتاج الحاضرين حالياً.</small>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا يوجد عمال إنتاج حاضرون حالياً. سيتم ربط التشغيلة بالعامل الحالي فقط.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php endif; ?>
                    
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
                            <textarea name="materials_used" class="form-control" rows="3" placeholder="<?php echo isset($lang['materials_used_placeholder']) ? $lang['materials_used_placeholder'] : 'أدرج المواد المستخدمة في العملية...'; ?>"></textarea>
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
                                <option value="approved"><?php echo isset($lang['approved']) ? $lang['approved'] : 'معتمد'; ?></option>
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

            const scrollTarget = document.querySelector('.production-tab-toggle') || sections[target];
            if (scrollTarget) {
                scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });
});

function renderTemplateSuppliers(details) {
    const cacheKey = details?.cache_key;
    if (!details || !details.success) {
        return;
    }
    if (cacheKey) {
        window.templateDetailsCache = window.templateDetailsCache || {};
        window.templateDetailsCache[cacheKey] = details;
    }
    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const hintText = document.getElementById('templateSuppliersHint');
    const summaryWrapper = document.getElementById('templateComponentsSummary');
    const summaryGrid = document.getElementById('templateComponentsSummaryGrid');
    const materialsInfoBox = document.getElementById('templateMaterialsInfo');

    if (!container || !wrapper || !modeInput) {
        return;
    }

    const components = Array.isArray(details?.components) ? details.components : [];

    container.innerHTML = '';
    if (summaryGrid) {
        summaryGrid.innerHTML = '';
    }
    if (summaryWrapper) {
        summaryWrapper.classList.add('d-none');
    }

    if (components.length === 0) {
        if (materialsInfoBox) {
            materialsInfoBox.innerHTML = '<div class="text-muted small">لا توجد مواد مرتبطة بهذا القالب حالياً.</div>';
        }
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

    const determineComponentType = (component) => {
        if (!component) {
            return 'generic';
        }
        const type = (component.type || '').toString().toLowerCase();
        if (type) {
            return type;
        }
        const key = (component.key || '').toString().toLowerCase();
        if (key.startsWith('pack_')) return 'packaging';
        if (key.startsWith('honey_')) return 'honey_raw';
        if (key.startsWith('raw_')) return 'raw_general';
        if (key.startsWith('olive')) return 'olive_oil';
        if (key.startsWith('beeswax')) return 'beeswax';
        if (key.startsWith('derivative')) return 'derivatives';
        if (key.startsWith('nuts')) return 'nuts';
        return 'generic';
    };

    const accentColors = {
        packaging: '#0dcaf0',
        honey_raw: '#f59e0b',
        honey_filtered: '#fb923c',
        honey_main: '#facc15',
        olive_oil: '#22c55e',
        beeswax: '#a855f7',
        derivatives: '#6366f1',
        nuts: '#d97706',
        raw_general: '#3b82f6',
        generic: '#2563eb',
        default: '#2563eb'
    };

    const typeLabelsMap = {
        packaging: 'أداة تعبئة',
        honey_raw: 'عسل خام',
        honey_filtered: 'عسل مصفى',
        honey_main: 'عسل',
        olive_oil: 'زيت زيتون',
        beeswax: 'شمع عسل',
        derivatives: 'مشتقات',
        nuts: 'مكسرات',
        raw_general: 'مادة خام',
        generic: 'مكوّن'
    };

    const componentIcons = {
        packaging: 'bi-box-seam',
        honey_raw: 'bi-droplet-half',
        honey_filtered: 'bi-droplet',
        honey_main: 'bi-bezier',
        olive_oil: 'bi-bezier2',
        beeswax: 'bi-hexagon',
        derivatives: 'bi-intersect',
        nuts: 'bi-record-circle',
        raw_general: 'bi-diagram-3',
        generic: 'bi-diagram-2'
    };

    const formatComponentQuantity = (component) => {
        if (!component) {
            return 'غير محدد';
        }
        const quantityDisplay = component.quantity_display || component.quantity_label || component.quantity_text;
        if (quantityDisplay && String(quantityDisplay).trim() !== '') {
            return quantityDisplay;
        }
        const unit = component.unit || component.unit_label || component.unit_name || '';
        const numericSource = component.quantity_per_unit ?? component.quantity ?? component.amount ?? null;
        if (numericSource !== null && numericSource !== undefined) {
            const numericValue = Number(numericSource);
            if (!Number.isNaN(numericValue)) {
                const formatted = numericValue.toLocaleString('ar-EG', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 3
                });
                return unit ? `${formatted} ${unit}` : formatted;
            }
            const rawString = String(numericSource).trim();
            if (rawString !== '') {
                return unit ? `${rawString} ${unit}` : rawString;
            }
        }
        return unit !== '' ? unit : 'غير محدد';
    };

    if (materialsInfoBox) {
        const infoItemsHtml = components.map((component, index) => {
            const canonicalType = determineComponentType(component);
            const typeLabel = typeLabelsMap[canonicalType] || typeLabelsMap.generic;
            const quantityLabel = formatComponentQuantity(component);
            const description = component.description || component.details || '';
            const name = component.name || component.label || `مكوّن رقم ${index + 1}`;
            return `
                <div class="materials-info-item">
                    <div class="materials-info-header">
                        <span class="materials-info-name">${name}</span>
                        <span class="materials-info-type badge bg-light text-primary border">
                            ${typeLabel}
                        </span>
                    </div>
                    <div class="materials-info-meta text-muted">
                        <i class="bi bi-basket me-1"></i>
                        <span>الكمية لكل تشغيلة: ${quantityLabel}</span>
                    </div>
                    ${description ? `<div class="materials-info-note text-muted">${description}</div>` : ''}
                </div>
            `;
        }).join('');

        materialsInfoBox.innerHTML = `
            <div class="materials-info-summary text-muted mb-2">
                تم تحميل ${components.length} من المواد والمكوّنات الخاصة بالقالب المحدد.
            </div>
            <div class="materials-info-list">
                ${infoItemsHtml}
            </div>
        `;
    }

    const stats = {
        total: components.length,
        packaging: 0,
        honey: 0,
        raw: 0,
        special: 0
    };

    components.forEach(component => {
        const canonicalType = determineComponentType(component);
        if (canonicalType === 'packaging') {
            stats.packaging += 1;
            return;
        }
        if (isHoneyComponent(component) || canonicalType === 'honey_raw' || canonicalType === 'honey_filtered' || canonicalType === 'honey_main') {
            stats.honey += 1;
            stats.raw += 1;
            return;
        }
        if (['olive_oil', 'beeswax', 'derivatives', 'nuts', 'raw_general'].includes(canonicalType) || canonicalType.startsWith('raw_')) {
            stats.raw += 1;
            return;
        }
        stats.special += 1;
    });

    stats.special = Math.max(0, stats.total - stats.raw - stats.packaging);

    if (summaryWrapper && summaryGrid) {
        const summaryItems = [
            { key: 'total', label: 'إجمالي المكوّنات', value: stats.total, icon: 'bi-collection' },
            { key: 'raw', label: 'مواد خام / أساسية', value: stats.raw, icon: 'bi-droplet-half' },
            { key: 'packaging', label: 'أدوات تعبئة', value: stats.packaging, icon: 'bi-box' },
            { key: 'honey', label: 'يتطلب نوع عسل', value: stats.honey, icon: 'bi-stars' },
            { key: 'special', label: 'مكوّنات خاصة', value: stats.special, icon: 'bi-puzzle' }
        ].filter(item => item.value > 0 || item.key === 'total');

        summaryGrid.innerHTML = summaryItems.map(item => `
            <div class="template-summary-item">
                <span class="template-summary-icon">
                    <i class="bi ${item.icon}"></i>
                </span>
                <div class="template-summary-content">
                    <span class="template-summary-value">${item.value}</span>
                    <span class="template-summary-label">${item.label}</span>
                </div>
            </div>
        `).join('');

        summaryWrapper.classList.remove('d-none');
    }

    const createChip = (iconClass, text) => {
        const chip = document.createElement('span');
        chip.className = 'component-card-chip';
        chip.innerHTML = `<i class="bi ${iconClass} me-1"></i>${text}`;
        return chip;
    };

    components.forEach(function(component) {
        const canonicalType = determineComponentType(component);
        const safeTypeClass = canonicalType.replace(/[^a-z0-9_-]/g, '') || 'generic';
        const componentKey = (component.key || component.name || ('component_' + Math.random().toString(36).slice(2)));

        const col = document.createElement('div');
        col.className = 'col-12 col-lg-6';

        const card = document.createElement('div');
        card.className = `component-card component-type-${safeTypeClass}`;
        card.style.setProperty('--component-accent', accentColors[canonicalType] || accentColors.default);

        const header = document.createElement('div');
        header.className = 'component-card-header';

        const title = document.createElement('span');
        title.className = 'component-card-title';
        title.textContent = component.name || component.label || 'مكوّن';

        const badge = document.createElement('span');
        badge.className = 'component-card-badge';
        badge.textContent = typeLabelsMap[canonicalType] || typeLabelsMap.generic;

        header.appendChild(title);
        header.appendChild(badge);
        card.appendChild(header);

        const meta = document.createElement('div');
        meta.className = 'component-card-meta';
        const metaIcon = document.createElement('i');
        metaIcon.className = `bi ${componentIcons[canonicalType] || componentIcons.generic} me-2`;
        meta.appendChild(metaIcon);
        const metaText = document.createElement('span');
        metaText.textContent = component.description || 'لا توجد تفاصيل إضافية.';
        meta.appendChild(metaText);
        card.appendChild(meta);

        const chipsWrapper = document.createElement('div');
        chipsWrapper.className = 'component-card-chips';
        if (component.requires_variety || isHoneyComponent(component)) {
            chipsWrapper.appendChild(createChip('bi-stars', 'يتطلب تحديد نوع العسل'));
        }
        if (component.default_supplier) {
            chipsWrapper.appendChild(createChip('bi-person-check', 'مورد مقترح'));
        }
        if (chipsWrapper.children.length > 0) {
            card.appendChild(chipsWrapper);
        }

        const controlLabel = document.createElement('label');
        controlLabel.className = 'form-label fw-semibold small text-muted mb-1';
        controlLabel.textContent = 'اختر المورد المناسب';
        card.appendChild(controlLabel);

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm component-supplier-select';
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
            noSupplierOption.textContent = 'لا يوجد مورد مناسب - راجع قائمة الموردين.';
            select.appendChild(noSupplierOption);
        }

        card.appendChild(select);

        if (isHoneyComponent(component)) {
            const honeyWrapper = document.createElement('div');
            honeyWrapper.className = 'mt-2';

            const honeyLabel = document.createElement('label');
            honeyLabel.className = 'form-label fw-bold mb-1';
            honeyLabel.textContent = 'نوع العسل لدى المورد المختار';

            const honeyInput = document.createElement('input');
            honeyInput.type = 'text';
            honeyInput.className = 'form-control form-control-sm';
            honeyInput.name = 'material_honey_varieties[' + componentKey + ']';
            honeyInput.required = true;
            honeyInput.dataset.role = 'honey-variety-input';
            honeyInput.dataset.defaultValue = component.honey_variety ? component.honey_variety : '';
            honeyInput.placeholder = 'اختر المورد أولاً';
            honeyInput.disabled = true;

            const honeyHelper = document.createElement('small');
            honeyHelper.className = 'text-muted d-block mt-1';
            honeyHelper.textContent = 'اختر أو اكتب نوع العسل المتوفر لدى المورد.';

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

            card.appendChild(honeyWrapper);

            populateHoneyVarietyOptions(honeyInput, datalist, select.value, component);
        }

        col.appendChild(card);
        container.appendChild(col);
    });

    wrapper.classList.remove('d-none');

    if (hintText) {
        hintText.textContent = details.hint || 'يرجى اختيار المورد المحاسب للمادة وتحديد نوع العسل عند الحاجة.';
    }

    currentTemplateMode = 'advanced';
    modeInput.value = 'advanced';
}

// تحميل بيانات الإنتاج عند التعديل
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

// حذف سجل إنتاج
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
    // TODO: إضافة نافذة لعرض التفاصيل كاملة
    alert('عرض تفاصيل الإنتاج #' + id);
}

// فتح نافذة إنشاء إنتاج من قالب
function openCreateFromTemplateModal(element) {
    const templateId = element.getAttribute('data-template-id');
    const templateName = element.getAttribute('data-template-name');
    const templateType = element.getAttribute('data-template-type') || 'legacy';
    
    try {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (scrollError) {
        window.scrollTo(0, 0);
    }
    
    document.querySelectorAll('.template-card-modern.selected-template').forEach(card => {
        card.classList.remove('selected-template');
        card.style.setProperty('--template-accent', card.dataset.originalAccent || '#0ea5e9');
        card.style.setProperty('--template-accent-light', card.dataset.originalAccentLight || 'rgba(14, 165, 233, 0.15)');
    });
    if (element) {
        element.classList.add('selected-template');
        element.dataset.originalAccent = getComputedStyle(element).getPropertyValue('--template-accent');
        element.dataset.originalAccentLight = getComputedStyle(element).getPropertyValue('--template-accent-light');
        element.style.setProperty('--template-accent', '#1d4ed8');
        element.style.setProperty('--template-accent-light', '#1d4ed822');
    }
    
    document.getElementById('template_id').value = templateId;
    document.getElementById('template_product_name').value = templateName;
    document.getElementById('template_type').value = templateType;
    
    const extraSuppliersSelect = document.getElementById('extraSuppliersSelect');
    if (extraSuppliersSelect) {
        Array.from(extraSuppliersSelect.options).forEach(option => {
            option.selected = false;
        });
    }
    const materialsInfoBox = document.getElementById('templateMaterialsInfo');
    if (materialsInfoBox) {
        materialsInfoBox.innerHTML = '<div class="text-muted small">سيتم عرض المواد والمقادير الخاصة بالقالب بعد اختيار قالب الإنتاج.</div>';
    }

    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const summaryWrapper = document.getElementById('templateComponentsSummary');
    const summaryGrid = document.getElementById('templateComponentsSummaryGrid');

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
    if (summaryGrid) {
        summaryGrid.innerHTML = '';
    }
    if (summaryWrapper) {
        summaryWrapper.classList.add('d-none');
    }
    currentTemplateMode = 'advanced';
    if (modeInput) {
        modeInput.value = 'advanced';
    }

    const templateCacheKey = templateId + '::' + templateType;
    window.templateDetailsCache = window.templateDetailsCache || {};

    const modalElement = document.getElementById('createFromTemplateModal');
    if (!modalElement) {
        console.error('createFromTemplateModal element not found in DOM.');
        return;
    }
    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    const showTemplateMessage = (html, wrapperClass = '') => {
        const materialsInfoBox = document.getElementById('templateMaterialsInfo');
        if (materialsInfoBox) {
            materialsInfoBox.innerHTML = html;
        }
        if (wrapper) {
            wrapper.classList.remove('d-none');
            if (wrapperClass) {
                wrapper.className = wrapper.className.replace(/\b(alert-\w+)\b/g, '').trim();
                wrapper.classList.add(wrapperClass);
            }
        }
        if (container) {
            container.innerHTML = '';
        }
    };

    const handleTemplateResponse = (data) => {
        if (data && data.success) {
            renderTemplateSuppliers(data);
        } else {
            showTemplateMessage(
                '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>لم يتم العثور على مواد لهذا القالب. يرجى مراجعة إعدادات القالب.</div>'
            );
        }
    };

    if (window.templateDetailsCache[templateCacheKey]) {
        handleTemplateResponse(window.templateDetailsCache[templateCacheKey]);
        return;
    }

    const createModalUrl = (relativePath) => {
        if (/^https?:\/\//i.test(relativePath)) {
            return relativePath;
        }
        try {
            return new URL(relativePath, window.location.origin).toString();
        } catch (error) {
            return window.location.origin + relativePath;
        }
    };

    const requestUrl = createModalUrl('/dashboard/production.php?page=production&ajax=template_details&template_id=' + templateId + '&template_type=' + encodeURIComponent(templateType));

    fetch(requestUrl, { cache: 'no-store' })
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Network error')))
        .then(data => {
            if (data && data.success) {
                const cacheKey = data.cache_key || templateCacheKey;
                window.templateDetailsCache[cacheKey] = data;
                window.templateDetailsCache[templateCacheKey] = data;
            }
            handleTemplateResponse(data);
        })
        .catch(error => {
            console.error('Error loading template details:', error);
            showTemplateMessage(
                `<div class="alert alert-danger mb-0"><i class="bi bi-x-circle me-2"></i>تعذّر تحميل بيانات القالب: ${error.message}</div>`
            );
        });
}

// إضافة معالجة للنماذج للتحقق من الحقول المطلوبة
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
                alert('يرجى اختيار المورد المناسب لهذه المادة للمتابعة');
                select.focus();
                return false;
            }
    }

    const honeyVarietyInputs = document.querySelectorAll('#templateSuppliersContainer input[data-role="honey-variety-input"]');
    for (let input of honeyVarietyInputs) {
        if (input.disabled) {
            e.preventDefault();
            alert('يرجى اختيار مورد العسل بعد تحديد النوع');
            input.focus();
            return false;
        }
        if (!input.value || !input.value.trim()) {
            e.preventDefault();
            alert('يرجى إدخال نوع العسل لدى المورد المختار');
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
        alert('لا توجد أرقام تشغيل للطباعة');
        return;
    }
    
    // طباعة الباركودات - كل باركود يحمل نفس رقم التشغيلة
    // الكمية المطلوبة للطباعة
    const batchNumber = batchNumbers[0]; // كل الباركودات تشترك في نفس الرقم
    const printUrl = 'print_barcode.php?batch=' + encodeURIComponent(batchNumber) + '&quantity=' + printQuantity + '&print=1';
    
    window.open(printUrl, '_blank');
}

// إدارة المواد الخام في نافذة إنشاء القالب
let rawMaterialIndex = 0;

function addRawMaterial() {
    const container = document.getElementById('rawMaterialsContainer');
    const materialHtml = `
        <div class="raw-material-item mb-2 border p-2 rounded">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">اسم المادة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][name]" 
                           placeholder="مثلاً: مكسرات أو عطر" required>
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
    
    // التحقق من إدخال اسم المنتج
    const productName = document.querySelector('input[name="product_name"]').value.trim();
    if (!productName) {
        e.preventDefault();
        alert('يرجى إدخال اسم المنتج');
        return false;
    }
});

<?php
// عرض نموذج الطباعة إذا تم إنشاء إنتاج من قالب
if (isset($_GET['show_barcode_modal']) && isset($_SESSION['created_batch_numbers'])) {
    $batchNumbers = $_SESSION['created_batch_numbers'];
    $productName = $_SESSION['created_batch_product_name'] ?? '';
    $quantity = $_SESSION['created_batch_quantity'] ?? count($batchNumbers);
    
    // تنظيف بيانات الجلسة
    unset($_SESSION['created_batch_numbers']);
    unset($_SESSION['created_batch_product_name']);
    unset($_SESSION['created_batch_quantity']);
    
    $batchNumbersJson = json_encode(array_values($batchNumbers), JSON_UNESCAPED_UNICODE);
    if ($batchNumbersJson === false) {
        $batchNumbersJson = '[]';
    }

    $productNameJson = json_encode($productName, JSON_UNESCAPED_UNICODE);
    if ($productNameJson === false) {
        $productNameJson = '""';
    }

    $firstBatchNumberJson = json_encode($batchNumbers[0] ?? '', JSON_UNESCAPED_UNICODE);
    if ($firstBatchNumberJson === false) {
        $firstBatchNumberJson = '""';
    }

    $quantityValue = (int) $quantity;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const batchNumbers = <?= $batchNumbersJson ?>;
        const firstBatchNumber = <?= $firstBatchNumberJson ?>;
        const productName = <?= $productNameJson ?>;
        const barcodeQuantity = <?= $quantityValue ?>;

        window.batchNumbersToPrint = Array.isArray(batchNumbers) ? batchNumbers : [];

        const productNameInput = document.getElementById('barcode_product_name');
        const quantityText = document.getElementById('barcode_quantity');
        const quantityInput = document.getElementById('barcode_print_quantity');
        const batchListContainer = document.getElementById('batch_numbers_list');
        const modalElement = document.getElementById('printBarcodesModal');

        if (productNameInput) {
            productNameInput.value = productName;
        }

        if (quantityText) {
            quantityText.textContent = barcodeQuantity;
        }

        if (quantityInput) {
            quantityInput.value = barcodeQuantity;
        }

        if (batchListContainer) {
            let batchListHtml = '<div class="alert alert-info mb-0">';
            batchListHtml += '<i class="bi bi-info-circle me-2"></i>';
            batchListHtml += '<strong>رقم التشغيلة:</strong> ' + (firstBatchNumber || '') + '<br>';
            batchListHtml += '<small>ستتم طباعة نفس رقم التشغيلة بعدد ' + barcodeQuantity + ' باركود</small>';
            batchListHtml += '</div>';
            batchListContainer.innerHTML = batchListHtml;
        }

        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
    });
    </script>
    <?php
}
?>
</script>

<?php
// معالجة طلبات AJAX الخاصة بتحميل بيانات الإنتاج
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
// منع تكرار الإرسال - إضافة رمز فريد لكل نموذج
document.addEventListener('DOMContentLoaded', function() {
    // البحث عن جميع النماذج في الصفحة
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    
    forms.forEach(function(form) {
        // التحقق من وجود حقل الرمز مسبقًا
        if (!form.querySelector('input[name="submit_token"]')) {
            // إنشاء رمز فريد
            const token = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            // إنشاء حقل مخفي للاحتفاظ بالرمز
            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'submit_token';
            tokenInput.value = token;
            
            // إلحاق الرمز بالنموذج
            form.appendChild(tokenInput);
        }
        
        // منع إعادة الإرسال عند الضغط على زر الإرسال
        form.addEventListener('submit', function(e) {
            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton) {
                // تعطيل الزر مؤقتًا
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
                
                // إعادة تفعيل الزر بعد 3 ثوانٍ (في حال فشل الإرسال)
                setTimeout(function() {
                    submitButton.disabled = false;
                    submitButton.style.opacity = '1';
                    submitButton.style.cursor = 'pointer';
                    if (submitButton.tagName === 'BUTTON') {
                        submitButton.innerHTML = originalText;
                    } else {
                        submitButton.value = originalText;
                    }
                    
                    // تحديث الرمز
                    const newToken = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const tokenInput = form.querySelector('input[name="submit_token"]');
                    if (tokenInput) {
                        tokenInput.value = newToken;
                    }
                }, 3000);
            }
        });
    });
    
    // منع إعادة الإرسال عند تحديث الصفحة (F5 أو Refresh)
    if (performance.navigation.type === 1) {
        // الصفحة تم تحديثها (Refresh)
        // إزالة أي رسائل خطأ قد تنتج عن إعادة الإرسال
        console.log('تم اكتشاف إعادة تحميل الصفحة - تم منع إعادة الإرسال');
    }
});

// تحذير عند محاولة مغادرة الصفحة مع وجود تغييرات
window.addEventListener('beforeunload', function(e) {
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    let formModified = false;
    
    forms.forEach(function(form) {
        // التحقق إذا تم تعديل أي حقل
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            if (input.value !== input.defaultValue) {
                formModified = true;
            }
        });
    });
    
    // لا تعرض تحذيرًا إذا لم يتم تعديل أي شيء
    if (!formModified) {
        return undefined;
    }
});
</script>