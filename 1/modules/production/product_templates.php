<?php
/**
 * صفحة قوالب المنتجات - نموذج مبسط
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// تعيين ترميز UTF-8
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/production_helper.php';

requireRole(['production', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] === 'template_details' && isset($_GET['template_id'])) {
    // تنظيف أي output buffer سابق لمنع إخراج HTML قبل JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    try {
        $templateId = intval($_GET['template_id'] ?? 0);
        
        if ($templateId <= 0) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'معرف القالب غير صالح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // جلب بيانات القالب
        $template = $db->queryOne(
            "SELECT pt.*, u.full_name as creator_name
             FROM product_templates pt
             LEFT JOIN users u ON pt.created_by = u.id
             WHERE pt.id = ?",
            [$templateId]
        );
        
        if (!$template) {
            throw new Exception('القالب غير موجود');
        }
        
        // جلب أدوات التعبئة
        $packagingDetails = $db->query(
            "SELECT ptp.packaging_material_id, ptp.packaging_name, ptp.quantity_per_unit, COALESCE(pm.unit, '') AS packaging_unit
             FROM product_template_packaging ptp
             LEFT JOIN packaging_materials pm ON pm.id = ptp.packaging_material_id
             WHERE ptp.template_id = ?",
            [$templateId]
        );
        
        $normalisedPackaging = [];
        foreach ($packagingDetails as $pack) {
            $packagingId = isset($pack['packaging_material_id']) ? (int)$pack['packaging_material_id'] : null;
            $normalisedPackaging[] = [
                'id' => $packagingId,
                'packaging_material_id' => $packagingId,
                'packaging_name' => trim((string)($pack['packaging_name'] ?? '')),
                'quantity_per_unit' => isset($pack['quantity_per_unit']) ? (float)$pack['quantity_per_unit'] : 1.0,
                'unit' => trim((string)($pack['packaging_unit'] ?? '')) ?: 'وحدة'
            ];
        }
        
        // جلب المواد الخام
        $rawMaterialsRows = $db->query(
            "SELECT material_name, material_type, quantity_per_unit, unit 
             FROM product_template_raw_materials 
             WHERE template_id = ?",
            [$templateId]
        );
        
        $materialDetails = [];
        foreach ($rawMaterialsRows as $raw) {
            $quantity = (float)($raw['quantity_per_unit'] ?? 0);
            $materialDetails[] = [
                'material_name' => $raw['material_name'],
                'material_type' => $raw['material_type'] ?? '',
                'quantity' => $quantity,
                'quantity_per_unit' => $quantity,
                'unit' => $raw['unit'] ?? 'وحدة'
            ];
        }
        
        // بناء payload
        $templateData = [
            'id' => $templateId,
            'product_name' => $template['product_name'],
            'status' => $template['status'] ?? 'active',
            'template_type' => $template['template_type'] ?? 'general',
            'unit_price' => $template['unit_price'] ?? null,
            'notes' => trim((string)($template['notes'] ?? '')),
            'raw_materials' => $materialDetails,
            'packaging' => $normalisedPackaging,
            'packaging_count' => count($normalisedPackaging),
            'raw_materials_count' => count($materialDetails)
        ];
        
        // تنظيف output buffer قبل إرسال JSON
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => $templateData
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
        
    } catch (Exception $e) {
        // تنظيف output buffer قبل إرسال JSON في حالة الخطأ
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        error_log("Error in AJAX template details: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'خطأ في جلب بيانات القالب: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// الحصول على رسالة النجاح من session (بعد redirect)
$sessionSuccess = getSuccessMessage();
if ($sessionSuccess) {
    $success = $sessionSuccess;
}

ensureProductTemplatesExtendedSchema($db);
syncAllUnifiedTemplatesToProductTemplates($db);

// إنشاء الجداول إذا لم تكن موجودة
try {
    $templateTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
    if (empty($templateTableCheck)) {
        // إنشاء جدول product_templates
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
              `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية العسل بالجرام',
              `template_type` varchar(50) DEFAULT 'general' COMMENT 'نوع القالب',
              `source_template_id` int(11) DEFAULT NULL COMMENT 'معرّف القالب في النظام القديم (إن وُجد)',
              `main_supplier_id` int(11) DEFAULT NULL COMMENT 'المورد الرئيسى للقالب',
              `notes` text DEFAULT NULL COMMENT 'ملاحظات إضافية',
              `details_json` longtext DEFAULT NULL COMMENT 'بيانات إضافية بتنسيق JSON',
              `status` enum('active','inactive') DEFAULT 'active',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`),
              KEY `source_template_id` (`source_template_id`),
              KEY `main_supplier_id` (`main_supplier_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // إضافة Foreign Key لاحقاً
        try {
            $usersTableCheck = $db->queryOne("SHOW TABLES LIKE 'users'");
            if (!empty($usersTableCheck)) {
                $db->execute("ALTER TABLE `product_templates` ADD CONSTRAINT `product_templates_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE");
            }
        } catch (Exception $fkError) {
            error_log("Foreign key creation error (non-critical): " . $fkError->getMessage());
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
            
            try {
                $db->execute("ALTER TABLE `product_template_packaging` ADD CONSTRAINT `product_template_packaging_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE");
                
                $packagingMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
                if (!empty($packagingMaterialsTableCheck)) {
                    $db->execute("ALTER TABLE `product_template_packaging` ADD CONSTRAINT `product_template_packaging_ibfk_2` FOREIGN KEY (`packaging_material_id`) REFERENCES `packaging_materials` (`id`) ON DELETE SET NULL");
                }
            } catch (Exception $fkError) {
                error_log("Foreign key creation error (non-critical): " . $fkError->getMessage());
            }
        }
        
        // إنشاء جدول product_template_raw_materials (للمواد الخام الأخرى مثل المكسرات)
        $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
        if (empty($rawMaterialsTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة (مثل: مكسرات، لوز، إلخ)',
                  `material_type` varchar(100) DEFAULT NULL COMMENT 'نوع المادة (مثل: honey_raw, honey_filtered)',
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالجرام',
                  `unit` varchar(50) DEFAULT 'جرام',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            try {
                $db->execute("ALTER TABLE `product_template_raw_materials` ADD CONSTRAINT `product_template_raw_materials_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE");
            } catch (Exception $fkError) {
                error_log("Foreign key creation error (non-critical): " . $fkError->getMessage());
            }
        } else {
            // إضافة عمود material_type إذا كان الجدول موجوداً ولكن العمود غير موجود
            try {
                $columnCheck = $db->queryOne("SHOW COLUMNS FROM `product_template_raw_materials` LIKE 'material_type'");
                if (empty($columnCheck)) {
                    $db->execute("ALTER TABLE `product_template_raw_materials` ADD COLUMN `material_type` varchar(100) DEFAULT NULL COMMENT 'نوع المادة (مثل: honey_raw, honey_filtered)' AFTER `material_name`");
                }
            } catch (Exception $colError) {
                error_log("Column addition error (non-critical): " . $colError->getMessage());
            }
        }
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
            
            try {
                $db->execute("ALTER TABLE `product_template_packaging` ADD CONSTRAINT `product_template_packaging_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE");
                
                $packagingMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
                if (!empty($packagingMaterialsTableCheck)) {
                    $db->execute("ALTER TABLE `product_template_packaging` ADD CONSTRAINT `product_template_packaging_ibfk_2` FOREIGN KEY (`packaging_material_id`) REFERENCES `packaging_materials` (`id`) ON DELETE SET NULL");
                }
            } catch (Exception $fkError) {
                error_log("Foreign key creation error (non-critical): " . $fkError->getMessage());
            }
        }
        
        $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
        if (empty($rawMaterialsTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة (مثل: مكسرات، لوز، إلخ)',
                  `material_type` varchar(100) DEFAULT NULL COMMENT 'نوع المادة (مثل: honey_raw, honey_filtered)',
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'الكمية بالجرام',
                  `unit` varchar(50) DEFAULT 'جرام',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            try {
                $db->execute("ALTER TABLE `product_template_raw_materials` ADD CONSTRAINT `product_template_raw_materials_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `product_templates` (`id`) ON DELETE CASCADE");
            } catch (Exception $fkError) {
                error_log("Foreign key creation error (non-critical): " . $fkError->getMessage());
            }
        } else {
            // إضافة عمود material_type إذا كان الجدول موجوداً ولكن العمود غير موجود
            try {
                $columnCheck = $db->queryOne("SHOW COLUMNS FROM `product_template_raw_materials` LIKE 'material_type'");
                if (empty($columnCheck)) {
                    $db->execute("ALTER TABLE `product_template_raw_materials` ADD COLUMN `material_type` varchar(100) DEFAULT NULL COMMENT 'نوع المادة (مثل: honey_raw, honey_filtered)' AFTER `material_name`");
                }
            } catch (Exception $colError) {
                error_log("Column addition error (non-critical): " . $colError->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Table creation error: " . $e->getMessage());
    $error = 'حدث خطأ في إنشاء الجداول: ' . $e->getMessage();
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_template') {
        $productName = trim($_POST['product_name'] ?? '');
        
        // أدوات التعبئة
        $packagingIds = [];
        if (isset($_POST['packaging_ids']) && is_array($_POST['packaging_ids'])) {
            $packagingIds = array_filter(array_map('intval', $_POST['packaging_ids']));
        }

        $packagingSelections = [];
        if (!empty($packagingIds)) {
            $packagingTableExists = false;
            try {
                $packagingTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
            } catch (Exception $e) {
                $packagingTableExists = false;
                error_log('Packaging table detection failed: ' . $e->getMessage());
            }

            $packagingMetadata = [];
            if ($packagingTableExists) {
                $placeholders = implode(', ', array_fill(0, count($packagingIds), '?'));
                try {
                    $packagingRows = $db->query(
                        "SELECT id, name, unit FROM packaging_materials WHERE id IN ($placeholders)",
                        $packagingIds
                    );
                    foreach ($packagingRows as $row) {
                        $rowId = isset($row['id']) ? (int)$row['id'] : null;
                        if ($rowId) {
                            $packagingMetadata[$rowId] = [
                                'name' => $row['name'] ?? '',
                                'unit' => $row['unit'] ?? ''
                            ];
                        }
                    }
                } catch (Exception $e) {
                    error_log('Packaging metadata lookup failed: ' . $e->getMessage());
                    $packagingMetadata = [];
                }
            }

            foreach ($packagingIds as $packagingId) {
                $metadata = $packagingMetadata[$packagingId] ?? ['name' => '', 'unit' => ''];
                $packagingName = trim((string)$metadata['name']) !== ''
                    ? trim((string)$metadata['name'])
                    : ('أداة تعبئة #' . $packagingId);
                $packagingUnit = trim((string)$metadata['unit']) !== ''
                    ? trim((string)$metadata['unit'])
                    : 'وحدة';

                $packagingSelections[] = [
                    'id' => $packagingId,
                    'name' => $packagingName,
                    'unit' => $packagingUnit,
                    'quantity_per_unit' => 1.0
                ];
            }
        }
        
        // المواد الخام الأخرى (للاستخدام لاحقاً)
        $rawMaterials = [];
        if (isset($_POST['raw_materials']) && is_array($_POST['raw_materials'])) {
            foreach ($_POST['raw_materials'] as $material) {
                // الحصول على اسم المادة (من القائمة المنسدلة أو من الحقل النصي)
                $materialName = trim($material['material_name'] ?? $material['name'] ?? '');
                if (empty($materialName) && !empty($material['name_custom'])) {
                    $materialName = trim($material['name_custom']);
                }
                
                // الحصول على نوع المادة (من القائمة المنسدلة أو من الحقل النصي)
                $materialType = trim($material['material_type'] ?? '');
                if (empty($materialType) && !empty($material['type_custom'])) {
                    $materialType = trim($material['type_custom']);
                }
                
                // بناء اسم المادة الكامل (اسم المادة - نوع المادة)
                $fullMaterialName = $materialName;
                if ($materialType !== '') {
                    $fullMaterialName = $materialName . ' - ' . $materialType;
                }
                
                // الحصول على حالة العسل (خام/مصفى) إذا كانت المادة عسل
                $honeyState = '';
                // التحقق من أن المادة هي عسل (وليس شمع عسل)
                $isHoneyMaterial = ($materialName === 'عسل' || strtolower($materialName) === 'honey') && 
                                  mb_stripos($materialName, 'شمع') === false && 
                                  stripos($materialName, 'beeswax') === false;
                if ($isHoneyMaterial) {
                    $honeyState = trim($material['honey_state'] ?? '');
                }
                
                if (!empty($materialName) && isset($material['quantity']) && $material['quantity'] > 0) {
                    $materialData = [
                        'name' => $fullMaterialName,
                        'material_name' => $materialName,
                        'material_type' => $materialType,
                        'quantity' => floatval($material['quantity']),
                        'unit' => trim($material['unit'] ?? 'كيلوجرام')
                    ];
                    
                    // إضافة حالة العسل إذا كانت محددة
                    if ($honeyState !== '') {
                        $materialData['honey_state'] = $honeyState;
                        // تحديث material_type بناءً على حالة العسل
                        if ($honeyState === 'raw') {
                            $materialData['material_type'] = 'honey_raw';
                        } elseif ($honeyState === 'filtered') {
                            $materialData['material_type'] = 'honey_filtered';
                        }
                    }
                    
                    $rawMaterials[] = $materialData;
                }
            }
        }
        
        $normalizedProductName = function_exists('mb_strtolower')
            ? mb_strtolower($productName, 'UTF-8')
            : strtolower($productName);
        $existingTemplate = null;
        if ($normalizedProductName !== '') {
            try {
                $existingTemplate = $db->queryOne(
                    "SELECT id FROM product_templates WHERE LOWER(product_name) = ? LIMIT 1",
                    [$normalizedProductName]
                );
            } catch (Exception $e) {
                error_log('Duplicate legacy template check failed: ' . $e->getMessage());
            }
        }

        if (empty($productName)) {
            $error = 'يجب إدخال اسم المنتج';
        } elseif ($existingTemplate) {
            $error = 'اسم المنتج مستخدم بالفعل. يرجى اختيار اسم مختلف.';
        } elseif (empty($packagingIds)) {
            $error = 'يجب اختيار أداة تعبئة واحدة على الأقل';
        } else {
            try {
                $db->beginTransaction();
                
                $rawMaterialsPayload = [];

                foreach ($rawMaterials as $materialEntry) {
                    $rawMaterialsPayload[] = [
                        'type' => 'ingredient',
                        'name' => $materialEntry['name'],
                        'material_name' => $materialEntry['material_name'] ?? $materialEntry['name'],
                        'material_type' => $materialEntry['material_type'] ?? '',
                        'quantity' => $materialEntry['quantity'],
                        'quantity_per_unit' => $materialEntry['quantity'],
                        'unit' => $materialEntry['unit']
                    ];
                }

                $packagingPayload = array_map(static function (array $packaging) {
                    return [
                        'id' => $packaging['id'],
                        'packaging_material_id' => $packaging['id'],
                        'name' => $packaging['name'],
                        'packaging_name' => $packaging['name'],
                        'quantity_per_unit' => $packaging['quantity_per_unit'],
                        'unit' => $packaging['unit']
                    ];
                }, $packagingSelections);

                $templateDetailsPayload = [
                    'product_name' => $productName,
                    'status' => 'active',
                    'template_type' => 'legacy',
                    'raw_materials' => $rawMaterialsPayload,
                    'packaging' => $packagingPayload,
                    'submitted_at' => date('c'),
                    'submitted_by' => $currentUser['id']
                ];

                $templateDetailsJson = json_encode(
                    $templateDetailsPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if ($templateDetailsJson === false) {
                    $templateDetailsJson = null;
                }

                // معالجة سعر الوحدة
                $unitPrice = null;
                if (isset($_POST['unit_price']) && $_POST['unit_price'] !== '') {
                    $rawPrice = trim((string)$_POST['unit_price']);
                    // تنظيف القيمة من 262145
                    $rawPrice = str_replace('262145', '', $rawPrice);
                    $rawPrice = preg_replace('/262145\s*/', '', $rawPrice);
                    $rawPrice = preg_replace('/\s*262145/', '', $rawPrice);
                    $unitPrice = cleanFinancialValue($rawPrice);
                    // التحقق من أن القيمة صحيحة
                    if (abs($unitPrice - 262145) < 0.01 || $unitPrice > 10000 || $unitPrice < 0) {
                        $unitPrice = null;
                    }
                }
                
                // إنشاء القالب
                $result = $db->execute(
                    "INSERT INTO product_templates (product_name, honey_quantity, created_by, status, template_type, main_supplier_id, notes, details_json, unit_price) 
                     VALUES (?, 0, ?, 'active', ?, NULL, NULL, ?, ?)",
                    [$productName, $currentUser['id'], 'legacy', $templateDetailsJson, $unitPrice]
                );
                
                $templateId = $result['insert_id'];
                
                // إضافة أدوات التعبئة
                foreach ($packagingSelections as $packaging) {
                    $db->execute(
                        "INSERT INTO product_template_packaging (template_id, packaging_material_id, packaging_name, quantity_per_unit) 
                         VALUES (?, ?, ?, 1.000)",
                        [$templateId, $packaging['id'], $packaging['name']]
                    );
                }
                
                // إضافة المواد الخام الأخرى
                foreach ($rawMaterials as $material) {
                    $materialType = $material['material_type'] ?? null;
                    $db->execute(
                        "INSERT INTO product_template_raw_materials (template_id, material_name, material_type, quantity_per_unit, unit) 
                         VALUES (?, ?, ?, ?, ?)",
                        [$templateId, $material['name'], $materialType, $material['quantity'], $material['unit']]
                    );
                }
                
                $db->commit();
                
                logAudit($currentUser['id'], 'create', 'product_template', $templateId, null, ['product_name' => $productName]);
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم إنشاء قالب المنتج بنجاح';
                $redirectParams = ['page' => 'product_templates'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'حدث خطأ في إنشاء القالب: ' . $e->getMessage();
                error_log("Error creating template: " . $e->getMessage());
            }
        }
    } elseif ($action === 'update_template') {
        $templateId = intval($_POST['template_id'] ?? 0);
        $productName = trim($_POST['product_name'] ?? '');
        
        // أدوات التعبئة
        $packagingIds = [];
        if (isset($_POST['packaging_ids']) && is_array($_POST['packaging_ids'])) {
            $packagingIds = array_filter(array_map('intval', $_POST['packaging_ids']));
        }

        $packagingSelections = [];
        if (!empty($packagingIds)) {
            $packagingTableExists = false;
            try {
                $packagingTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
            } catch (Exception $e) {
                $packagingTableExists = false;
                error_log('Packaging table detection failed: ' . $e->getMessage());
            }

            $packagingMetadata = [];
            if ($packagingTableExists) {
                $placeholders = implode(', ', array_fill(0, count($packagingIds), '?'));
                try {
                    $packagingRows = $db->query(
                        "SELECT id, name, unit FROM packaging_materials WHERE id IN ($placeholders)",
                        $packagingIds
                    );
                    foreach ($packagingRows as $row) {
                        $rowId = isset($row['id']) ? (int)$row['id'] : null;
                        if ($rowId) {
                            $packagingMetadata[$rowId] = [
                                'name' => $row['name'] ?? '',
                                'unit' => $row['unit'] ?? ''
                            ];
                        }
                    }
                } catch (Exception $e) {
                    error_log('Packaging metadata lookup failed: ' . $e->getMessage());
                    $packagingMetadata = [];
                }
            }

            foreach ($packagingIds as $packagingId) {
                $metadata = $packagingMetadata[$packagingId] ?? ['name' => '', 'unit' => ''];
                $packagingName = trim((string)$metadata['name']) !== ''
                    ? trim((string)$metadata['name'])
                    : ('أداة تعبئة #' . $packagingId);
                $packagingUnit = trim((string)$metadata['unit']) !== ''
                    ? trim((string)$metadata['unit'])
                    : 'وحدة';

                $packagingSelections[] = [
                    'id' => $packagingId,
                    'name' => $packagingName,
                    'unit' => $packagingUnit,
                    'quantity_per_unit' => 1.0
                ];
            }
        }
        
        // المواد الخام الأخرى
        $rawMaterials = [];
        if (isset($_POST['raw_materials']) && is_array($_POST['raw_materials'])) {
            foreach ($_POST['raw_materials'] as $material) {
                // الحصول على اسم المادة (من القائمة المنسدلة أو من الحقل النصي)
                $materialName = trim($material['material_name'] ?? $material['name'] ?? '');
                if (empty($materialName) && !empty($material['name_custom'])) {
                    $materialName = trim($material['name_custom']);
                }
                
                // الحصول على نوع المادة (من القائمة المنسدلة أو من الحقل النصي)
                $materialType = trim($material['material_type'] ?? '');
                if (empty($materialType) && !empty($material['type_custom'])) {
                    $materialType = trim($material['type_custom']);
                }
                
                // بناء اسم المادة الكامل (اسم المادة - نوع المادة)
                $fullMaterialName = $materialName;
                if ($materialType !== '') {
                    $fullMaterialName = $materialName . ' - ' . $materialType;
                }
                
                // الحصول على حالة العسل (خام/مصفى) إذا كانت المادة عسل
                $honeyState = '';
                // التحقق من أن المادة هي عسل (وليس شمع عسل)
                $isHoneyMaterial = ($materialName === 'عسل' || strtolower($materialName) === 'honey') && 
                                  mb_stripos($materialName, 'شمع') === false && 
                                  stripos($materialName, 'beeswax') === false;
                if ($isHoneyMaterial) {
                    $honeyState = trim($material['honey_state'] ?? '');
                }
                
                if (!empty($materialName) && isset($material['quantity']) && $material['quantity'] > 0) {
                    $materialData = [
                        'name' => $fullMaterialName,
                        'material_name' => $materialName,
                        'material_type' => $materialType,
                        'quantity' => floatval($material['quantity']),
                        'unit' => trim($material['unit'] ?? 'كيلوجرام')
                    ];
                    
                    // إضافة حالة العسل إذا كانت محددة
                    if ($honeyState !== '') {
                        $materialData['honey_state'] = $honeyState;
                        // تحديث material_type بناءً على حالة العسل
                        if ($honeyState === 'raw') {
                            $materialData['material_type'] = 'honey_raw';
                        } elseif ($honeyState === 'filtered') {
                            $materialData['material_type'] = 'honey_filtered';
                        }
                    }
                    
                    $rawMaterials[] = $materialData;
                }
            }
        }
        
        $normalizedProductName = function_exists('mb_strtolower')
            ? mb_strtolower($productName, 'UTF-8')
            : strtolower($productName);
        $existingTemplate = null;
        if ($normalizedProductName !== '' && $templateId > 0) {
            try {
                $existingTemplate = $db->queryOne(
                    "SELECT id FROM product_templates WHERE LOWER(product_name) = ? AND id != ? LIMIT 1",
                    [$normalizedProductName, $templateId]
                );
            } catch (Exception $e) {
                error_log('Duplicate template check failed: ' . $e->getMessage());
            }
        }

        if ($templateId <= 0) {
            $error = 'معرّف القالب غير صحيح';
        } elseif (empty($productName)) {
            $error = 'يجب إدخال اسم المنتج';
        } elseif ($existingTemplate) {
            $error = 'اسم المنتج مستخدم بالفعل. يرجى اختيار اسم مختلف.';
        } elseif (empty($packagingIds)) {
            $error = 'يجب اختيار أداة تعبئة واحدة على الأقل';
        } else {
            try {
                $db->beginTransaction();
                
                // التحقق من وجود القالب
                $templateExists = $db->queryOne(
                    "SELECT id FROM product_templates WHERE id = ?",
                    [$templateId]
                );
                
                if (!$templateExists) {
                    throw new Exception('القالب غير موجود');
                }
                
                $rawMaterialsPayload = [];

                foreach ($rawMaterials as $materialEntry) {
                    $rawMaterialsPayload[] = [
                        'type' => 'ingredient',
                        'name' => $materialEntry['name'],
                        'material_name' => $materialEntry['material_name'] ?? $materialEntry['name'],
                        'material_type' => $materialEntry['material_type'] ?? '',
                        'quantity' => $materialEntry['quantity'],
                        'quantity_per_unit' => $materialEntry['quantity'],
                        'unit' => $materialEntry['unit']
                    ];
                }

                $packagingPayload = array_map(static function (array $packaging) {
                    return [
                        'id' => $packaging['id'],
                        'packaging_material_id' => $packaging['id'],
                        'name' => $packaging['name'],
                        'packaging_name' => $packaging['name'],
                        'quantity_per_unit' => $packaging['quantity_per_unit'],
                        'unit' => $packaging['unit']
                    ];
                }, $packagingSelections);

                $templateDetailsPayload = [
                    'product_name' => $productName,
                    'status' => 'active',
                    'template_type' => 'legacy',
                    'raw_materials' => $rawMaterialsPayload,
                    'packaging' => $packagingPayload,
                    'submitted_at' => date('c'),
                    'submitted_by' => $currentUser['id']
                ];

                $templateDetailsJson = json_encode(
                    $templateDetailsPayload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if ($templateDetailsJson === false) {
                    $templateDetailsJson = null;
                }

                // معالجة سعر الوحدة
                $unitPrice = null;
                if (isset($_POST['unit_price']) && $_POST['unit_price'] !== '') {
                    $rawPrice = trim((string)$_POST['unit_price']);
                    // تنظيف القيمة من 262145
                    $rawPrice = str_replace('262145', '', $rawPrice);
                    $rawPrice = preg_replace('/262145\s*/', '', $rawPrice);
                    $rawPrice = preg_replace('/\s*262145/', '', $rawPrice);
                    $unitPrice = cleanFinancialValue($rawPrice);
                    // التحقق من أن القيمة صحيحة
                    if (abs($unitPrice - 262145) < 0.01 || $unitPrice > 10000 || $unitPrice < 0) {
                        $unitPrice = null;
                    }
                }
                
                // تحديث القالب
                $db->execute(
                    "UPDATE product_templates 
                     SET product_name = ?, details_json = ?, unit_price = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$productName, $templateDetailsJson, $unitPrice, $templateId]
                );
                
                // حذف أدوات التعبئة القديمة
                $db->execute("DELETE FROM product_template_packaging WHERE template_id = ?", [$templateId]);
                
                // إضافة أدوات التعبئة الجديدة
                foreach ($packagingSelections as $packaging) {
                    $db->execute(
                        "INSERT INTO product_template_packaging (template_id, packaging_material_id, packaging_name, quantity_per_unit) 
                         VALUES (?, ?, ?, 1.000)",
                        [$templateId, $packaging['id'], $packaging['name']]
                    );
                }
                
                // حذف المواد الخام القديمة
                $db->execute("DELETE FROM product_template_raw_materials WHERE template_id = ?", [$templateId]);
                
                // إضافة المواد الخام الجديدة
                foreach ($rawMaterials as $material) {
                    $materialType = $material['material_type'] ?? null;
                    $db->execute(
                        "INSERT INTO product_template_raw_materials (template_id, material_name, material_type, quantity_per_unit, unit) 
                         VALUES (?, ?, ?, ?, ?)",
                        [$templateId, $material['name'], $materialType, $material['quantity'], $material['unit']]
                    );
                }
                
                $db->commit();
                
                logAudit($currentUser['id'], 'update', 'product_template', $templateId, null, ['product_name' => $productName]);
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم تحديث قالب المنتج بنجاح';
                $redirectParams = ['page' => 'product_templates'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'حدث خطأ في تحديث القالب: ' . $e->getMessage();
                error_log("Error updating template: " . $e->getMessage());
            }
        }
    } elseif ($action === 'delete_template') {
        $templateId = intval($_POST['template_id'] ?? 0);
        
        if ($templateId > 0) {
            try {
                $db->execute("DELETE FROM product_templates WHERE id = ?", [$templateId]);
                logAudit($currentUser['id'], 'delete', 'product_template', $templateId, null);
                
                // منع التكرار باستخدام redirect
                $successMessage = 'تم حذف القالب بنجاح';
                $redirectParams = ['page' => 'product_templates'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'خطأ في حذف القالب: ' . $e->getMessage();
            }
        }
    }
}

// الحصول على القوالب بعد مزامنة النظام الموحد
$templates = $db->query(
    "SELECT pt.*, 
            u.full_name as creator_name
     FROM product_templates pt
     LEFT JOIN users u ON pt.created_by = u.id
     ORDER BY pt.created_at DESC"
);

$packagingMaterialsTableExistsForDetails = false;
try {
    $packagingMaterialsTableExistsForDetails = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
} catch (Exception $packagingMetaException) {
    $packagingMaterialsTableExistsForDetails = false;
}

foreach ($templates as &$template) {
    $templateId = (int)($template['id'] ?? 0);

    // أدوات التعبئة
    if ($packagingMaterialsTableExistsForDetails) {
        $packagingDetails = $db->query(
            "SELECT ptp.packaging_material_id, ptp.packaging_name, ptp.quantity_per_unit, COALESCE(pm.unit, '') AS packaging_unit
             FROM product_template_packaging ptp
             LEFT JOIN packaging_materials pm ON pm.id = ptp.packaging_material_id
             WHERE ptp.template_id = ?",
            [$templateId]
        );
    } else {
        $packagingDetails = $db->query(
            "SELECT packaging_material_id, packaging_name, quantity_per_unit, '' AS packaging_unit
             FROM product_template_packaging 
             WHERE template_id = ?",
            [$templateId]
        );
    }

    $normalisedPackaging = [];
    foreach ($packagingDetails as $pack) {
        $packName = trim((string)($pack['packaging_name'] ?? ''));
        if ($packName === '') {
            $packId = (int)($pack['packaging_material_id'] ?? 0);
            $packName = $packId > 0 ? ('أداة تعبئة #' . $packId) : 'أداة تعبئة';
        }

        $quantityPerUnit = isset($pack['quantity_per_unit']) ? (float)$pack['quantity_per_unit'] : 0.0;
        if ($quantityPerUnit <= 0) {
            $quantityPerUnit = 1.0;
        }

        $packUnit = trim((string)($pack['packaging_unit'] ?? ''));
        if ($packUnit === '') {
            $packUnit = 'وحدة';
        }

        $normalisedPackaging[] = [
            'packaging_material_id' => isset($pack['packaging_material_id']) ? (int)$pack['packaging_material_id'] : null,
            'packaging_name'        => $packName,
            'quantity_per_unit'     => $quantityPerUnit,
            'unit'                  => $packUnit,
        ];
    }

    $template['packaging_details'] = $normalisedPackaging;
    $template['packaging_count'] = count($normalisedPackaging);

    // المواد الخام
    $rawMaterialsRows = $db->query(
        "SELECT material_name, material_type, quantity_per_unit, unit 
         FROM product_template_raw_materials 
         WHERE template_id = ?",
        [$templateId]
    );

    $materialDetails = [];
    foreach ($rawMaterialsRows as $raw) {
        $materialDetails[] = [
            'material_name' => $raw['material_name'],
            'material_type' => $raw['material_type'] ?? '',
            'quantity_per_unit' => (float)($raw['quantity_per_unit'] ?? 0),
            'unit' => $raw['unit'] ?? 'وحدة'
        ];
    }

    $template['material_details'] = $materialDetails;
    $template['raw_materials_count'] = count($materialDetails);
    $template['template_type'] = $template['template_type'] ?: 'general';
    $template['products_count'] = 1;

    $statusLabel = $template['status'] === 'active' ? 'نشط' : 'غير نشط';
    $createdAtLabel = formatDate($template['created_at']);

    $template['status_label'] = $statusLabel;
    $template['created_at_label'] = $createdAtLabel;

    $template['details_payload'] = [
        'id'                 => $templateId,
        'product_name'       => $template['product_name'],
        'status'             => $template['status'],
        'status_label'       => $statusLabel,
        'template_type'      => $template['template_type'],
        'notes'              => trim((string)($template['notes'] ?? '')),
        'created_at_label'   => $createdAtLabel,
        'creator_name'       => (string)($template['creator_name'] ?? ''),
        'honey_quantity'     => isset($template['honey_quantity']) ? (float)$template['honey_quantity'] : 0.0,
        'raw_materials'      => $materialDetails,
        'packaging'          => $normalisedPackaging,
        'packaging_count'    => count($normalisedPackaging),
        'raw_materials_count'=> count($materialDetails),
    ];
}
unset($template);

// الحصول على أدوات التعبئة
$packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$packagingMaterials = [];
if (!empty($packagingTableCheck)) {
    try {
        $packagingMaterials = $db->query(
            "SELECT id, name, type, quantity, unit FROM packaging_materials WHERE status = 'active' ORDER BY name"
        );
        // التأكد من ترميز UTF-8 لجميع البيانات
        foreach ($packagingMaterials as &$pkg) {
            if (isset($pkg['name'])) {
                $pkg['name'] = mb_convert_encoding($pkg['name'], 'UTF-8', 'UTF-8');
            }
            if (isset($pkg['unit'])) {
                $pkg['unit'] = mb_convert_encoding($pkg['unit'], 'UTF-8', 'UTF-8');
            }
        }
        unset($pkg);
    } catch (Exception $e) {
        error_log('Failed to load packaging materials: ' . $e->getMessage());
        $packagingMaterials = [];
    }
}

// جلب المواد الخام من مخزن الخامات مع أنواعها
$rawMaterialsData = [];

// جلب العسل وأنواعه من مخزن العسل (من الموردين)
try {
    $honeyStockExists = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
    if (!empty($honeyStockExists)) {
        // جلب أنواع العسل الموجودة فعلياً عند الموردين في المخزن
        $honeyVarieties = $db->query("
            SELECT DISTINCT hs.honey_variety, 
                   COUNT(DISTINCT hs.supplier_id) as suppliers_count
            FROM honey_stock hs
            INNER JOIN suppliers s ON hs.supplier_id = s.id
            WHERE hs.honey_variety IS NOT NULL 
            AND hs.honey_variety != '' 
            AND (hs.raw_honey_quantity > 0 OR hs.filtered_honey_quantity > 0)
            AND s.status = 'active'
            GROUP BY hs.honey_variety
            ORDER BY hs.honey_variety
        ");
        $honeyTypes = [];
        foreach ($honeyVarieties as $variety) {
            $varietyName = trim($variety['honey_variety']);
            if ($varietyName !== '' && !in_array($varietyName, $honeyTypes)) {
                $honeyTypes[] = $varietyName;
            }
        }
        
        $hasRawHoney = $db->queryOne("
            SELECT COUNT(DISTINCT supplier_id) as count 
            FROM honey_stock hs
            INNER JOIN suppliers s ON hs.supplier_id = s.id
            WHERE hs.raw_honey_quantity > 0 
            AND s.status = 'active'
        ");
        $hasFilteredHoney = $db->queryOne("
            SELECT COUNT(DISTINCT supplier_id) as count 
            FROM honey_stock hs
            INNER JOIN suppliers s ON hs.supplier_id = s.id
            WHERE hs.filtered_honey_quantity > 0 
            AND s.status = 'active'
        ");
        
        if (!empty($honeyTypes) || ($hasRawHoney && $hasRawHoney['count'] > 0) || ($hasFilteredHoney && $hasFilteredHoney['count'] > 0)) {
            $rawMaterialsData['عسل'] = [
                'material_type' => 'honey',
                'has_types' => !empty($honeyTypes),
                'types' => $honeyTypes
            ];
        }
    }
} catch (Exception $e) {
    error_log('Failed to load honey varieties from suppliers: ' . $e->getMessage());
}

// جلب المكسرات وأنواعها من مخزن المكسرات (من الموردين)
try {
    $nutsStockExists = $db->queryOne("SHOW TABLES LIKE 'nuts_stock'");
    $mixedNutsExists = $db->queryOne("SHOW TABLES LIKE 'mixed_nuts'");
    
    $nutVarieties = [];
    
    // جلب المكسرات المنفردة المتاحة في المخزن
    if (!empty($nutsStockExists)) {
        $nutsTypes = $db->query("
            SELECT DISTINCT ns.nut_type,
                   COUNT(DISTINCT ns.supplier_id) as suppliers_count,
                   SUM(ns.quantity) as total_quantity
            FROM nuts_stock ns
            INNER JOIN suppliers s ON ns.supplier_id = s.id
            WHERE ns.nut_type IS NOT NULL 
            AND ns.nut_type != '' 
            AND ns.quantity > 0
            AND s.status = 'active'
            GROUP BY ns.nut_type
            HAVING total_quantity > 0
            ORDER BY ns.nut_type
        ");
        
        foreach ($nutsTypes as $nut) {
            $nutName = trim($nut['nut_type']);
            if ($nutName !== '' && !in_array($nutName, $nutVarieties)) {
                $nutVarieties[] = $nutName;
            }
        }
    }
    
    // جلب المكسرات المشكلة (الخلطات) المتاحة في المخزن
    if (!empty($mixedNutsExists)) {
        $mixedNuts = $db->query("
            SELECT DISTINCT mn.id,
                   mn.batch_name,
                   mn.total_quantity
            FROM mixed_nuts mn
            INNER JOIN suppliers s ON mn.supplier_id = s.id
            WHERE mn.batch_name IS NOT NULL 
            AND mn.batch_name != '' 
            AND mn.total_quantity > 0
            AND s.status = 'active'
            ORDER BY mn.batch_name
        ");
        
        foreach ($mixedNuts as $mixed) {
            $mixedName = trim($mixed['batch_name']);
            if ($mixedName !== '' && !in_array($mixedName, $nutVarieties)) {
                // إضافة "مكسرات مشكلة:" كبادئة للتمييز
                $nutVarieties[] = $mixedName;
            }
        }
    }
    
    if (!empty($nutVarieties)) {
        // المكسرات لها أنواع (لوز، جوز، مكسرات مشكلة، إلخ) - نضيف "مكسرات" كاسم مادة وأنواعها
        $rawMaterialsData['مكسرات'] = [
            'material_type' => 'nuts',
            'has_types' => true,
            'types' => $nutVarieties
        ];
    }
} catch (Exception $e) {
    error_log('Failed to load nuts from suppliers: ' . $e->getMessage());
}

// جلب زيت الزيتون من مخزن زيت الزيتون
try {
    $oliveOilExists = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
    if (!empty($oliveOilExists)) {
        // التحقق من وجود زيت الزيتون المتاح من الموردين النشطين
        $hasOliveOil = $db->queryOne("
            SELECT COUNT(*) as count 
            FROM olive_oil_stock os
            INNER JOIN suppliers s ON os.supplier_id = s.id
            WHERE os.quantity > 0 
            AND s.status = 'active'
        ");
        if ($hasOliveOil && $hasOliveOil['count'] > 0) {
            $rawMaterialsData['زيت زيتون'] = [
                'material_type' => 'olive_oil',
                'has_types' => false,
                'types' => []
            ];
        }
    }
} catch (Exception $e) {
    error_log('Failed to load olive oil: ' . $e->getMessage());
}

// جلب شمع العسل من مخزن شمع العسل
try {
    $beeswaxExists = $db->queryOne("SHOW TABLES LIKE 'beeswax_stock'");
    if (!empty($beeswaxExists)) {
        // التحقق من وجود شمع العسل المتاح من الموردين النشطين
        $hasBeeswax = $db->queryOne("
            SELECT COUNT(*) as count 
            FROM beeswax_stock bs
            INNER JOIN suppliers s ON bs.supplier_id = s.id
            WHERE bs.weight > 0 
            AND s.status = 'active'
        ");
        if ($hasBeeswax && $hasBeeswax['count'] > 0) {
            // إضافة "شمع عسل" فقط (إزالة "شمع" لتجنب التكرار)
            $rawMaterialsData['شمع عسل'] = [
                'material_type' => 'beeswax',
                'has_types' => false,
                'types' => []
            ];
        }
    }
} catch (Exception $e) {
    error_log('Failed to load beeswax: ' . $e->getMessage());
}

// جلب المشتقات وأنواعها من مخزن المشتقات (من الموردين)
try {
    $derivativesExists = $db->queryOne("SHOW TABLES LIKE 'derivatives_stock'");
    if (!empty($derivativesExists)) {
        // جلب أنواع المشتقات الموجودة فعلياً عند الموردين في المخزن
        $derivativesTypes = $db->query("
            SELECT DISTINCT ds.derivative_type,
                   COUNT(DISTINCT ds.supplier_id) as suppliers_count,
                   SUM(ds.weight) as total_weight
            FROM derivatives_stock ds
            INNER JOIN suppliers s ON ds.supplier_id = s.id
            WHERE ds.derivative_type IS NOT NULL 
            AND ds.derivative_type != '' 
            AND ds.weight > 0
            AND s.status = 'active'
            GROUP BY ds.derivative_type
            HAVING total_weight > 0
            ORDER BY ds.derivative_type
        ");
        $derivativeVarieties = [];
        foreach ($derivativesTypes as $derivative) {
            $derivativeName = trim($derivative['derivative_type']);
            // إزالة استبعاد "غذاء الملكات" - السماح بجميع أنواع المشتقات
            if ($derivativeName !== '' && 
                !in_array($derivativeName, $derivativeVarieties)) {
                $derivativeVarieties[] = $derivativeName;
            }
        }
        
        // إضافة "مشتقات" كاسم مادة دائماً مع أنواعها (حتى لو كان نوع واحد فقط)
        if (!empty($derivativeVarieties)) {
            $rawMaterialsData['مشتقات'] = [
                'material_type' => 'derivatives',
                'has_types' => true,
                'types' => $derivativeVarieties
            ];
        }
    }
} catch (Exception $e) {
    error_log('Failed to load derivatives from suppliers: ' . $e->getMessage());
}

// إنشاء قائمة بأسماء المواد فقط للعرض في القائمة المنسدلة
// المواد الخام الأساسية الخمسة فقط: عسل، زيت زيتون، شمع عسل، مشتقات، مكسرات
$allowedMaterials = ['عسل', 'زيت زيتون', 'شمع عسل', 'مشتقات', 'مكسرات'];
$rawMaterialsForTemplate = array_intersect($allowedMaterials, array_keys($rawMaterialsData));

sort($rawMaterialsForTemplate);

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<div class="page-header mb-4 d-flex justify-content-between align-items-center flex-wrap">
    <h2 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>قوالب المنتجات</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
        <i class="bi bi-plus-circle me-2"></i>إنشاء قالب جديد
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
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toast = document.createElement('div');
        toast.className = 'template-toast';
        toast.innerHTML = '<i class="bi bi-check-circle-fill"></i><?php echo htmlspecialchars($success); ?>';
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => toast.classList.remove('show'), 4000);
        setTimeout(() => toast.remove(), 4600);
    });
    </script>
<?php endif; ?>


<!-- قائمة القوالب -->
<?php if (empty($templates)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="text-muted">لا توجد قوالب منتجات</h5>
            <p class="text-muted">ابدأ بإنشاء قالب منتج جديد</p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                <i class="bi bi-plus-circle me-2"></i>إنشاء قالب جديد
            </button>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">قائمة القوالب (<?php echo count($templates); ?>)</h5>
    </div>
    
    <div class="row g-4">
        <?php foreach ($templates as $template): ?>
            <?php
            $packaging = $template['packaging_details'] ?? [];
            $rawMaterials = $template['material_details'] ?? [];

            $primaryMaterialName = $rawMaterials[0]['material_name'] ?? '';
            $packagingPreview = array_slice($packaging, 0, 3);
            $rawMaterialsPreview = array_slice($rawMaterials, 0, 3);
            $remainingPackaging = max(0, count($packaging) - count($packagingPreview));
            $remainingRawMaterials = max(0, count($rawMaterials) - count($rawMaterialsPreview));
            // تحضير JSON للاستخدام في JavaScript (base64 encoding لتجنب مشاكل escaping)
            $templateDetailsJson = '';
            $templateDetailsJsonBase64 = '';
            
            // التأكد من أن details_payload موجود ومكون بشكل صحيح
            if (!empty($template['details_payload']) && is_array($template['details_payload'])) {
                try {
                    $encodedDetails = json_encode(
                        $template['details_payload'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
                    );
                    
                    if ($encodedDetails !== false && $encodedDetails !== '' && $encodedDetails !== 'null') {
                        // استخدام base64 مع UTF-8 encoding صحيح للتشغيل في JavaScript
                        // في PHP: base64_encode يعمل بشكل صحيح مع UTF-8
                        // في JavaScript: نحتاج استخدام TextDecoder لفك تشفير UTF-8 بشكل صحيح بعد atob()
                        $templateDetailsJsonBase64 = base64_encode($encodedDetails);
                        $templateDetailsJson = $encodedDetails; // للاستخدام المباشر في JavaScript
                    } else {
                        // إذا فشل encoding، أنشئ payload افتراضي
                        error_log("Template #{$template['id']}: Failed to encode details_payload");
                        $templateDetailsJson = json_encode([
                            'id' => $template['id'],
                            'product_name' => $template['product_name'] ?? 'غير محدد',
                            'status' => $template['status'] ?? 'active',
                            'raw_materials' => $template['material_details'] ?? [],
                            'packaging' => $template['packaging_details'] ?? []
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $templateDetailsJsonBase64 = base64_encode($templateDetailsJson);
                    }
                } catch (Exception $jsonError) {
                    error_log("Template #{$template['id']}: JSON encoding error: " . $jsonError->getMessage());
                    // إنشاء payload افتراضي في حالة الخطأ
                    $templateDetailsJson = json_encode([
                        'id' => $template['id'],
                        'product_name' => $template['product_name'] ?? 'غير محدد',
                        'status' => $template['status'] ?? 'active',
                        'raw_materials' => $template['material_details'] ?? [],
                        'packaging' => $template['packaging_details'] ?? []
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $templateDetailsJsonBase64 = base64_encode($templateDetailsJson);
                }
            } else {
                // إذا لم يكن details_payload موجوداً، أنشئ واحداً من البيانات المتاحة
                $templateDetailsJson = json_encode([
                    'id' => $template['id'],
                    'product_name' => $template['product_name'] ?? 'غير محدد',
                    'status' => $template['status'] ?? 'active',
                    'raw_materials' => $template['material_details'] ?? [],
                    'packaging' => $template['packaging_details'] ?? []
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $templateDetailsJsonBase64 = base64_encode($templateDetailsJson);
            }
            
            // التأكد من أن القيم ليست فارغة
            if (empty($templateDetailsJson) || empty($templateDetailsJsonBase64)) {
                $templateDetailsJson = '{}';
                $templateDetailsJsonBase64 = base64_encode('{}');
            }
            $materialIconTheme = [
                'icon' => 'bi-box-seam',
                'color' => '#0d6efd',
                'background' => 'rgba(13, 110, 253, 0.12)'
            ];

            $materialKeywordThemes = [
                'عسل' => ['icon' => 'bi-droplet-fill', 'color' => '#d97706', 'background' => 'rgba(217, 119, 6, 0.18)'],
                'شمع' => ['icon' => 'bi-hexagon-fill', 'color' => '#f97316', 'background' => 'rgba(249, 115, 22, 0.18)'],
                'زيت' => ['icon' => 'bi-bucket-fill', 'color' => '#15803d', 'background' => 'rgba(21, 128, 61, 0.16)'],
                'زيوت' => ['icon' => 'bi-bucket-fill', 'color' => '#15803d', 'background' => 'rgba(21, 128, 61, 0.16)'],
                'مكسر' => ['icon' => 'bi-nut', 'color' => '#7c3aed', 'background' => 'rgba(124, 58, 237, 0.16)'],
                'لوز' => ['icon' => 'bi-nut', 'color' => '#7c3aed', 'background' => 'rgba(124, 58, 237, 0.16)'],
                'بندق' => ['icon' => 'bi-nut', 'color' => '#7c3aed', 'background' => 'rgba(124, 58, 237, 0.16)'],
                'فستق' => ['icon' => 'bi-nut', 'color' => '#7c3aed', 'background' => 'rgba(124, 58, 237, 0.16)'],
                'سكر' => ['icon' => 'bi-cup-straw', 'color' => '#db2777', 'background' => 'rgba(219, 39, 119, 0.15)'],
                'ماء' => ['icon' => 'bi-droplet', 'color' => '#0284c7', 'background' => 'rgba(2, 132, 199, 0.15)'],
                'لقاح' => ['icon' => 'bi-flower1', 'color' => '#0ea5e9', 'background' => 'rgba(14, 165, 233, 0.16)'],
                'حبوب' => ['icon' => 'bi-flower1', 'color' => '#0ea5e9', 'background' => 'rgba(14, 165, 233, 0.16)'],
                'مشتق' => ['icon' => 'bi-diagram-3', 'color' => '#2563eb', 'background' => 'rgba(37, 99, 235, 0.14)']
            ];

            $normalizedMaterialName = $primaryMaterialName;
            if ($normalizedMaterialName !== '') {
                $normalizedMaterialName = function_exists('mb_strtolower')
                    ? mb_strtolower($normalizedMaterialName, 'UTF-8')
                    : strtolower($normalizedMaterialName);
                foreach ($materialKeywordThemes as $keyword => $theme) {
                    $positionFound = function_exists('mb_stripos')
                        ? mb_stripos($normalizedMaterialName, $keyword, 0, 'UTF-8')
                        : stripos($normalizedMaterialName, $keyword);
                    if ($positionFound !== false) {
                        $materialIconTheme = $theme;
                        break;
                    }
                }
            }

            if ($materialIconTheme['icon'] === 'bi-box-seam') {
                $templateTypeThemes = [
                    'honey' => ['icon' => 'bi-droplet-fill', 'color' => '#d97706', 'background' => 'rgba(217, 119, 6, 0.18)'],
                    'honey_filtered' => ['icon' => 'bi-droplet-fill', 'color' => '#d97706', 'background' => 'rgba(217, 119, 6, 0.18)'],
                    'honey_raw' => ['icon' => 'bi-droplet-half', 'color' => '#d97706', 'background' => 'rgba(217, 119, 6, 0.18)'],
                    'olive_oil' => ['icon' => 'bi-bucket-fill', 'color' => '#15803d', 'background' => 'rgba(21, 128, 61, 0.16)'],
                    'beeswax' => ['icon' => 'bi-hexagon-fill', 'color' => '#f97316', 'background' => 'rgba(249, 115, 22, 0.18)'],
                    'derivatives' => ['icon' => 'bi-diagram-3', 'color' => '#2563eb', 'background' => 'rgba(37, 99, 235, 0.14)'],
                    'nuts' => ['icon' => 'bi-nut', 'color' => '#7c3aed', 'background' => 'rgba(124, 58, 237, 0.16)'],
                    'general' => ['icon' => 'bi-box-seam', 'color' => '#0d6efd', 'background' => 'rgba(13, 110, 253, 0.12)']
                ];
                $templateType = $template['template_type'] ?? 'general';
                if (isset($templateTypeThemes[$templateType])) {
                    $materialIconTheme = $templateTypeThemes[$templateType];
                } else {
                    $materialIconTheme = $templateTypeThemes['general'];
                }
            }

            $iconStyle = sprintf(
                'background-color:%s; color:%s;',
                htmlspecialchars($materialIconTheme['background'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($materialIconTheme['color'], ENT_QUOTES, 'UTF-8')
            );
            $cardAccentColor = htmlspecialchars($materialIconTheme['color'], ENT_QUOTES, 'UTF-8');
            $statusBadgeClass = $template['status'] === 'active' ? 'bg-success' : 'bg-secondary';
            $statusLabel = $template['status_label'] ?? ($template['status'] === 'active' ? 'نشط' : 'غير نشط');
            $createdAtLabel = $template['created_at_label'] ?? formatDate($template['created_at']);
            ?>
            <div class="col-lg-4 col-md-6">
                <div class="card shadow-sm h-100 template-card" style="border-top: 4px solid <?php echo $cardAccentColor; ?>; transition: transform 0.2s, box-shadow 0.2s;">
                    <span class="badge template-status-badge <?php echo $statusBadgeClass; ?>"><?php echo $statusLabel; ?></span>
                    <div class="card-body template-card-body text-center">
                        <div class="template-icon" style="<?php echo $iconStyle; ?>" title="<?php echo htmlspecialchars($primaryMaterialName ?: 'نوع المادة غير محدد'); ?>">
                            <i class="bi <?php echo htmlspecialchars($materialIconTheme['icon']); ?>"></i>
                        </div>
                        <h4 class="template-product-name">
                            <?php echo htmlspecialchars($template['product_name']); ?>
                        </h4>
                        <div class="template-details-snippet text-start w-100 mt-4">
                            <div class="template-snippet-section mb-3">
                                <div class="template-snippet-header">
                                    <i class="bi bi-diagram-3 me-1"></i>
                                    المواد الخام
                                </div>
                                <?php if (empty($rawMaterialsPreview)): ?>
                                    <div class="template-snippet-empty">لا توجد مواد خام مسجلة.</div>
                                <?php else: ?>
                                    <ul class="template-snippet-list mb-0">
                                        <?php foreach ($rawMaterialsPreview as $item): ?>
                                            <li>
                                                <span class="template-snippet-name"><?php echo htmlspecialchars($item['material_name']); ?></span>
                                                <span class="template-snippet-qty">
                                                    <?php echo number_format((float)$item['quantity_per_unit'], 3); ?>
                                                    <span class="template-snippet-unit"><?php echo htmlspecialchars($item['unit'] ?? 'وحدة'); ?></span>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($remainingRawMaterials > 0): ?>
                                        <div class="template-snippet-more">+<?php echo $remainingRawMaterials; ?> عناصر إضافية</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="template-snippet-section">
                                <div class="template-snippet-header">
                                    <i class="bi bi-box2-heart me-1"></i>
                                    أدوات التعبئة
                                </div>
                                <?php if (empty($packagingPreview)): ?>
                                    <div class="template-snippet-empty">لا توجد أدوات تعبئة مسجلة.</div>
                                <?php else: ?>
                                    <ul class="template-snippet-list mb-0">
                                        <?php foreach ($packagingPreview as $item): ?>
                                            <li>
                                                <span class="template-snippet-name"><?php echo htmlspecialchars($item['packaging_name']); ?></span>
                                                <span class="template-snippet-qty">
                                                    <?php echo number_format((float)$item['quantity_per_unit'], 3); ?>
                                                    <span class="template-snippet-unit"><?php echo htmlspecialchars($item['unit'] ?? 'وحدة'); ?></span>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if ($remainingPackaging > 0): ?>
                                        <div class="template-snippet-more">+<?php echo $remainingPackaging; ?> عناصر إضافية</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer template-card-footer d-flex justify-content-between align-items-center">
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary"
                                    data-template="<?php echo $templateDetailsJsonBase64; ?>"
                                    data-template-encoded="base64"
                                    onclick="showTemplateDetails(this)">
                                <i class="bi bi-info-circle me-1"></i>
                                عرض التفاصيل
                            </button>
                        </div>
                        <div class="d-flex align-items-center text-muted small">
                            <i class="bi bi-calendar3 me-1"></i>
                            <?php echo $createdAtLabel; ?>
                        </div>
                        <?php if ($currentUser['role'] === 'manager'): ?>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary"
                                        data-template-id="<?php echo $template['id']; ?>"
                                        data-template-data="<?php echo $templateDetailsJsonBase64; ?>"
                                        data-template-encoded="base64"
                                        onclick="editTemplateFromButton(this)"
                                        title="تعديل القالب">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger"
                                        onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['product_name']); ?>')"
                                        title="حذف القالب">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.template-card {
    cursor: pointer;
    position: relative;
    border-top-width: 4px;
    border-top-style: solid;
}

.template-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.75rem 1.25rem rgba(15, 23, 42, 0.18) !important;
}

.template-card:hover .template-icon {
    transform: scale(1.05);
}

.template-status-badge {
    position: absolute;
    top: 14px;
    inset-inline-end: 14px;
    padding: 0.4rem 0.65rem;
    font-size: 0.75rem;
    border-radius: 999px;
}

.template-card-body {
    min-height: 220px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2.75rem 1.5rem 1.75rem;
    text-align: center;
}

.template-icon {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.75rem;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
    transition: transform 0.2s ease;
}

.template-product-name {
    font-size: 1.4rem;
    font-weight: 700;
    color: #0f172a;
    margin-top: 1.5rem;
    margin-bottom: 0;
    line-height: 1.4;
}

.template-card-footer {
    background-color: transparent;
    border-top: none;
    padding: 0 1.5rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.template-card-footer .btn {
    flex-shrink: 0;
}

.template-details-snippet {
    font-size: 0.9rem;
    color: #475569;
}

.template-snippet-section {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem 0.9rem;
    background-color: #f8fafc;
}

.template-snippet-section + .template-snippet-section {
    margin-top: 0.75rem;
}

.template-snippet-header {
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 0.5rem;
}

.template-snippet-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.template-snippet-list li {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.25rem 0;
    border-bottom: 1px dashed rgba(148, 163, 184, 0.4);
}

.template-snippet-list li:last-child {
    border-bottom: none;
}

.template-snippet-name {
    font-weight: 500;
    color: #0f172a;
}

.template-snippet-qty {
    font-weight: 600;
    color: #0ea5e9;
}

.template-snippet-unit {
    font-weight: 500;
    color: #64748b;
    margin-inline-start: 0.25rem;
}

.template-snippet-empty {
    color: #94a3b8;
    font-size: 0.85rem;
}

.template-snippet-more {
    margin-top: 0.35rem;
    font-size: 0.8rem;
    color: #64748b;
}

.template-details-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem 1rem;
    align-items: center;
    font-size: 0.92rem;
    color: #475569;
}

.template-details-meta-item {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.template-details-notes {
    background-color: #fef3c7;
    color: #92400e;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    line-height: 1.6;
}

.details-section-title {
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 0.75rem;
}

.details-list-wrapper {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.75rem 1rem;
    background-color: #f8fafc;
    min-height: 180px;
}

.details-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.details-list li {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding: 0.4rem 0;
    border-bottom: 1px dashed rgba(148, 163, 184, 0.35);
    font-size: 0.95rem;
}

.details-list li:last-child {
    border-bottom: none;
}

.details-list-name {
    font-weight: 600;
    color: #0f172a;
}

.details-list-qty {
    font-weight: 600;
    color: #0ea5e9;
}

.details-list-unit {
    font-weight: 500;
    color: #64748b;
    margin-inline-start: 0.25rem;
}

.details-list-empty {
    color: #94a3b8;
    font-size: 0.9rem;
}

.template-toast {
    position: fixed;
    bottom: 24px;
    inset-inline-end: 24px;
    z-index: 1060;
    padding: 0.9rem 1.2rem;
    border-radius: 14px;
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(6, 95, 212, 0.95));
    color: #fff;
    font-weight: 600;
    box-shadow: 0 18px 35px rgba(15, 23, 42, 0.28);
    opacity: 0;
    transform: translateY(20px);
    transition: opacity 0.35s ease, transform 0.35s ease;
    pointer-events: none;
}

.template-toast.show {
    opacity: 1;
    transform: translateY(0);
}

.template-toast i {
    margin-inline-end: 0.6rem;
}

@media (max-width: 768px) {
    .template-card {
        margin-bottom: 1rem;
    }
    .template-card-body {
        padding: 2.25rem 1.25rem 1.5rem;
        min-height: 200px;
    }
    .template-details-snippet {
        font-size: 0.85rem;
    }
    .template-snippet-section {
        padding: 0.6rem 0.75rem;
    }
    .details-list-wrapper {
        min-height: auto;
    }
    .template-icon {
        width: 82px;
        height: 82px;
        font-size: 2.35rem;
    }
    .template-product-name {
        font-size: 1.25rem;
    }
    .template-toast {
        inset-inline: 16px;
        bottom: 16px;
    }
}
</style>

<!-- Modal إنشاء قالب جديد -->
<div class="modal fade" id="createTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إنشاء قالب منتج جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createTemplateForm">
                <input type="hidden" name="action" value="create_template">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج الجديد <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" required 
                               placeholder="مثل: عسل بالجوز 720 جرام">
                        <small class="text-muted">أدخل اسم المنتج الذي سيتم إنتاجه</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">أدوات التعبئة المستخدمة <span class="text-danger">*</span></label>
                        <?php if (empty($packagingMaterials)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا توجد أدوات تعبئة متاحة. يرجى إضافة أدوات التعبئة أولاً من صفحة مخزن أدوات التعبئة.
                            </div>
                        <?php else: ?>
                            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;" id="packagingCheckboxContainer">
                                <?php foreach ($packagingMaterials as $pkg): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="packaging_ids[]" 
                                               value="<?php echo $pkg['id']; ?>" 
                                               id="packaging_<?php echo $pkg['id']; ?>">
                                        <label class="form-check-label w-100" for="packaging_<?php echo $pkg['id']; ?>">
                                            <span class="fw-semibold"><?php echo htmlspecialchars($pkg['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($pkg['quantity'])): ?>
                                                <span class="text-muted small ms-2">
                                                    (المخزون: <?php echo number_format((float)($pkg['quantity'] ?? 0), 2); ?> <?php echo htmlspecialchars($pkg['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-info-circle me-1"></i>يمكنك اختيار أكثر من أداة تعبئة
                            </small>
                        <?php endif; ?>
                    </div>
                    
                    <!-- سعر الوحدة -->
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة (<?php echo htmlspecialchars(function_exists('getCurrencySymbol') ? getCurrencySymbol() : (CURRENCY_SYMBOL ?? 'ج.م')); ?>)</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo htmlspecialchars(function_exists('getCurrencySymbol') ? getCurrencySymbol() : (CURRENCY_SYMBOL ?? 'ج.م')); ?></span>
                            <input type="number" 
                                   class="form-control" 
                                   name="unit_price" 
                                   step="0.01" 
                                   min="0" 
                                   placeholder="0.00"
                                   value="">
                        </div>
                        <small class="text-muted">سعر بيع الوحدة الواحدة من المنتج (اختياري)</small>
                    </div>
                    
                    <!-- المواد الخام الأخرى (للاستخدام لاحقاً) -->
                    <div class="mb-3">
                        <label class="form-label">المواد الخام الأساسية</label>
                        <p class="text-muted small mb-2">
                            أضف جميع المكوّنات المستخدمة في المنتج (مثل العسل، المكسرات، الإضافات...).<br>
                            <strong>ملاحظة:</strong> نوع العسل يتم تحديده لاحقاً أثناء إنشاء تشغيلة الإنتاج.
                        </p>
                        <div id="rawMaterialsContainer">
                            <!-- سيتم إضافة المواد هنا ديناميكياً -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addRawMaterial()">
                            <i class="bi bi-plus"></i> إضافة مادة خام
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>إنشاء القالب
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل قالب -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل قالب منتج</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editTemplateForm">
                <input type="hidden" name="action" value="update_template">
                <input type="hidden" name="template_id" id="editTemplateId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" id="editProductName" required 
                               placeholder="مثل: عسل بالجوز 720 جرام">
                        <small class="text-muted">أدخل اسم المنتج الذي سيتم إنتاجه</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">أدوات التعبئة المستخدمة <span class="text-danger">*</span></label>
                        <?php if (empty($packagingMaterials)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا توجد أدوات تعبئة متاحة. يرجى إضافة أدوات التعبئة أولاً من صفحة مخزن أدوات التعبئة.
                            </div>
                        <?php else: ?>
                            <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;" id="editPackagingCheckboxContainer">
                                <?php foreach ($packagingMaterials as $pkg): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="packaging_ids[]" 
                                               value="<?php echo $pkg['id']; ?>" 
                                               id="edit_packaging_<?php echo $pkg['id']; ?>">
                                        <label class="form-check-label w-100" for="edit_packaging_<?php echo $pkg['id']; ?>">
                                            <span class="fw-semibold"><?php echo htmlspecialchars($pkg['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php if (!empty($pkg['quantity'])): ?>
                                                <span class="text-muted small ms-2">
                                                    (المخزون: <?php echo number_format((float)($pkg['quantity'] ?? 0), 2); ?> <?php echo htmlspecialchars($pkg['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted d-block mt-2">
                                <i class="bi bi-info-circle me-1"></i>يمكنك اختيار أكثر من أداة تعبئة
                            </small>
                        <?php endif; ?>
                    </div>
                    
                    <!-- سعر الوحدة -->
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة (<?php echo htmlspecialchars(function_exists('getCurrencySymbol') ? getCurrencySymbol() : (CURRENCY_SYMBOL ?? 'ج.م')); ?>)</label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo htmlspecialchars(function_exists('getCurrencySymbol') ? getCurrencySymbol() : (CURRENCY_SYMBOL ?? 'ج.م')); ?></span>
                            <input type="number" 
                                   class="form-control" 
                                   name="unit_price" 
                                   id="editUnitPrice"
                                   step="0.01" 
                                   min="0" 
                                   placeholder="0.00"
                                   value="">
                        </div>
                        <small class="text-muted">سعر بيع الوحدة الواحدة من المنتج (اختياري)</small>
                    </div>
                    
                    <!-- المواد الخام الأخرى -->
                    <div class="mb-3">
                        <label class="form-label">المواد الخام الأساسية</label>
                        <p class="text-muted small mb-2">
                            أضف جميع المكوّنات المستخدمة في المنتج (مثل العسل، المكسرات، الإضافات...).<br>
                            <strong>ملاحظة:</strong> نوع العسل يتم تحديده لاحقاً أثناء إنشاء تشغيلة الإنتاج.
                        </p>
                        <div id="editRawMaterialsContainer">
                            <!-- سيتم إضافة المواد هنا ديناميكياً -->
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addEditRawMaterial()">
                            <i class="bi bi-plus"></i> إضافة مادة خام
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>حفظ التعديلات
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تفاصيل القالب -->
<div class="modal fade" id="templateDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle me-2"></i>
                    <span id="templateDetailsTitle">تفاصيل القالب</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="template-details-meta mb-3">
                    <span class="badge rounded-pill px-3 py-2" id="templateDetailsStatusBadge">-</span>
                    <span class="template-details-meta-item">
                        <i class="bi bi-calendar-event me-1"></i>
                        <span id="templateDetailsCreatedAt">-</span>
                    </span>
                    <span class="template-details-meta-item">
                        <i class="bi bi-person-circle me-1"></i>
                        <span id="templateDetailsCreator">-</span>
                    </span>
                    <span class="template-details-meta-item">
                        <i class="bi bi-diagram-3 me-1"></i>
                        <span id="templateDetailsType">-</span>
                    </span>
                    <span class="template-details-meta-item">
                        <i class="bi bi-droplet-half me-1"></i>
                        <span id="templateDetailsHoney">-</span>
                    </span>
                </div>
                <div id="templateDetailsNotes" class="template-details-notes mb-4 d-none"></div>
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="details-section-title">
                            <i class="bi bi-diagram-3 me-2"></i>المواد الخام
                        </h6>
                        <div id="templateDetailsRawMaterials" class="details-list-wrapper"></div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="details-section-title">
                            <i class="bi bi-box2-heart me-2"></i>أدوات التعبئة
                        </h6>
                        <div id="templateDetailsPackaging" class="details-list-wrapper"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تفاصيل الباركود للتشغيلة -->
<div class="modal fade" id="batchBarcodeModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-upc-scan me-2"></i>باركود التشغيلة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success d-flex align-items-center">
                    <i class="bi bi-check-circle-fill fs-4 me-3 text-success"></i>
                    <div>
                        <strong>تم إنشاء التشغيله بنجاح!</strong>
                        <div class="small text-muted">يمكنك الآن طباعة الباركود أو حفظه كملف PDF.</div>
                    </div>
                </div>

                <div class="row g-4 align-items-center">
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 text-center bg-light">
                            <h6 class="text-muted mb-3">رقم التشغيلة</h6>
                            <div id="batchBarcodeNumber" class="fs-4 fw-bold text-primary"></div>
                            <div class="barcode-preview mt-3">
                                <canvas id="batchBarcodeCanvas" class="img-fluid"></canvas>
                            </div>
                            <div class="mt-2 text-muted small">
                                <span class="d-block">المنتج: <strong id="batchBarcodeProduct"></strong></span>
                                <span class="d-block">تاريخ الإنتاج: <strong id="batchBarcodeProductionDate"></strong></span>
                                <span class="d-block">تاريخ الانتهاء: <strong id="batchBarcodeExpiryDate"></strong></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">عدد الباركودات المطلوب طباعتها</label>
                            <input type="number" class="form-control" id="batchBarcodeQuantityInput" min="1" value="1">
                            <div class="form-text">يستخدم عدد المنتجات في التشغيلة كقيمة افتراضية.</div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary" onclick="printSingleBatchBarcode()">
                                <i class="bi bi-printer me-2"></i>طباعة باركود واحد
                            </button>
                            <button type="button" class="btn btn-primary" onclick="printMultipleBatchBarcodes()">
                                <i class="bi bi-printer-fill me-2"></i>طباعة الكمية المحددة
                            </button>
                            <button type="button" class="btn btn-outline-success" onclick="downloadBatchBarcodePdf()">
                                <i class="bi bi-file-earmark-pdf me-2"></i>تحميل الباركود كملف PDF
                            </button>
                        </div>
                        <div class="mt-3">
                            <div class="small text-muted">
                                يمكنك مشاركة ملف الـ PDF مباشرةً مع فريق الطباعة أو حفظه للأرشفة.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js" crossorigin="anonymous"></script>
<script>
let rawMaterialIndex = 0;

const PRINT_BARCODE_URL = '<?php echo addslashes(getRelativeUrl("print_barcode.php")); ?>';
let lastCreatedBatchInfo = null;

document.getElementById('createTemplateModal')?.addEventListener('show.bs.modal', function () {
    const container = document.getElementById('rawMaterialsContainer');
    if (container) {
        container.innerHTML = '';
        rawMaterialIndex = 0;
        addRawMaterial({ name: 'عسل', unit: 'كيلوجرام' });
    }
});

document.getElementById('createTemplateModal')?.addEventListener('hidden.bs.modal', function () {
    const container = document.getElementById('rawMaterialsContainer');
    if (container) {
        container.innerHTML = '';
    }
    rawMaterialIndex = 0;
});

// قائمة بالمواد الخام من مخزن الخامات مع أنواعها
const rawMaterialsData = <?php echo json_encode($rawMaterialsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const commonMaterials = <?php echo json_encode($rawMaterialsForTemplate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function addRawMaterial(defaults = {}) {
    const container = document.getElementById('rawMaterialsContainer');
    if (!container) {
        return;
    }
    const defaultName = typeof defaults.name === 'string' ? defaults.name : '';
    const defaultQuantity = typeof defaults.quantity === 'number' ? defaults.quantity : '';
    const defaultUnit = typeof defaults.unit === 'string' ? defaults.unit : 'كيلوجرام';
    
    // بناء خيارات القائمة المنسدلة
    const materialOptions = commonMaterials.map(material => {
        const selected = material === defaultName ? 'selected' : '';
        return `<option value="${material}" ${selected}>${material}</option>`;
    }).join('');
    
    // تقسيم اسم المادة الافتراضية إذا كان يحتوي على "عسل" أو " - "
    let defaultMaterialName = defaultName;
    let defaultMaterialType = '';
    let defaultHoneyState = defaults.honey_state || '';
    
    if (defaultName && defaultName.includes(' - ')) {
        const parts = defaultName.split(' - ', 2);
        if (parts.length === 2) {
            defaultMaterialName = parts[0].trim(); // "عسل"
            defaultMaterialType = parts[1].trim(); // "حبة البركة" مثلاً
        }
    } else if (defaultName && defaultName.startsWith('عسل ') && defaultName.length > 5) {
        const parts = defaultName.split(' ', 2);
        if (parts.length === 2) {
            defaultMaterialName = parts[0]; // "عسل"
            defaultMaterialType = parts[1]; // "حبة البركة" مثلاً
        }
    }
    
    const materialHtml = `
        <div class="raw-material-item mb-2 border p-2 rounded bg-light">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">اسم المادة <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm material-name-select" 
                            name="raw_materials[${rawMaterialIndex}][material_name]" 
                            data-material-index="${rawMaterialIndex}" 
                            data-material-name-select="${rawMaterialIndex}"
                            required>
                        <option value="">-- اختر المادة --</option>
                        ${materialOptions}
                        <option value="__custom__">-- إضافة مادة جديدة --</option>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1 d-none" 
                           name="raw_materials[${rawMaterialIndex}][name_custom]" 
                           id="custom_material_${rawMaterialIndex}" 
                           placeholder="أدخل اسم المادة الجديدة">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">نوع المادة</label>
                    <select class="form-select form-select-sm material-type-select" 
                            name="raw_materials[${rawMaterialIndex}][material_type]" 
                            id="material_type_${rawMaterialIndex}"
                            data-material-type-select="${rawMaterialIndex}">
                        <option value="">-- لا يوجد نوع --</option>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1 d-none" 
                           name="raw_materials[${rawMaterialIndex}][type_custom]" 
                           id="custom_type_${rawMaterialIndex}" 
                           placeholder="أدخل نوع المادة">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">حالة العسل</label>
                    <select class="form-select form-select-sm honey-state-select" 
                            name="raw_materials[${rawMaterialIndex}][honey_state]" 
                            id="honey_state_${rawMaterialIndex}"
                            data-honey-state-select="${rawMaterialIndex}">
                        <option value="">-- لا ينطبق --</option>
                        <option value="raw" ${defaultHoneyState === 'raw' ? 'selected' : ''}>خام</option>
                        <option value="filtered" ${defaultHoneyState === 'filtered' ? 'selected' : ''}>مصفى</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الكمية <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][quantity]" 
                           step="0.001" min="0.001" placeholder="0.000" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الوحدة</label>
                    <input type="text" class="form-control form-control-sm material-unit-input" 
                           name="raw_materials[${rawMaterialIndex}][unit]" 
                           id="material_unit_${rawMaterialIndex}"
                           value="كيلوجرام" readonly style="background-color: #e9ecef;">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeRawMaterial(this)">
                        <i class="bi bi-trash"></i> حذف
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', materialHtml);
    const newItem = container.lastElementChild;
    if (newItem) {
        const materialSelect = newItem.querySelector(`select[data-material-name-select="${rawMaterialIndex}"]`);
        const materialTypeSelect = newItem.querySelector(`select[data-material-type-select="${rawMaterialIndex}"]`);
        const customMaterialInput = newItem.querySelector(`#custom_material_${rawMaterialIndex}`);
        const customTypeInput = newItem.querySelector(`#custom_type_${rawMaterialIndex}`);
        const quantityInput = newItem.querySelector(`input[name="raw_materials[${rawMaterialIndex}][quantity]"]`);
        const honeyStateSelect = newItem.querySelector(`select[data-honey-state-select="${rawMaterialIndex}"]`);
        
        // دالة لتحديث قائمة أنواع المادة وحقل حالة العسل
        const updateMaterialTypes = function(selectedMaterialName, selectedMaterialType) {
            if (!materialTypeSelect) return;
            
            // مسح القائمة الحالية
            materialTypeSelect.innerHTML = '<option value="">-- لا يوجد نوع --</option>';
            materialTypeSelect.classList.add('d-none');
            customTypeInput.classList.add('d-none');
            
            // إخفاء حقل حالة العسل افتراضياً
            if (honeyStateSelect) {
                honeyStateSelect.classList.add('d-none');
            }
            
            if (!selectedMaterialName || selectedMaterialName === '' || selectedMaterialName === '__custom__') {
                return;
            }
            
            // التحقق من أن المادة هي عسل (وليس شمع عسل)
            // يجب أن تكون المادة "عسل" فقط وليس "شمع عسل"
            const isHoneyMaterial = (selectedMaterialName === 'عسل' || selectedMaterialName.toLowerCase() === 'honey') && 
                                   !selectedMaterialName.includes('شمع') && 
                                   !selectedMaterialName.toLowerCase().includes('beeswax');
            
            const materialData = rawMaterialsData[selectedMaterialName];
            
            // التحقق من أن المادة هي مشتقات وتحديث الوحدة
            const isDerivativesMaterial = selectedMaterialName === 'مشتقات' || 
                                         (materialData && materialData.material_type === 'derivatives');
            
            // تحديث الوحدة للمشتقات لتكون "كيلوجرام" (الوزن)
            const unitInput = newItem.querySelector(`#material_unit_${rawMaterialIndex}`);
            if (unitInput) {
                if (isDerivativesMaterial) {
                    unitInput.value = 'كيلوجرام';
                } else {
                    unitInput.value = defaultUnit || 'كيلوجرام';
                }
            }
            if (materialData && materialData.has_types && materialData.types && materialData.types.length > 0) {
                // إظهار القائمة المنسدلة للأنواع
                materialTypeSelect.classList.remove('d-none');
                
                materialData.types.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type;
                    option.textContent = type;
                    if (type === defaultMaterialType || type === selectedMaterialType) {
                        option.selected = true;
                    }
                    materialTypeSelect.appendChild(option);
                });
                
                // إذا كانت المادة عسل ونوع المادة محدد، أظهر حقل حالة العسل
                const finalMaterialType = selectedMaterialType || materialTypeSelect.value;
                if (isHoneyMaterial && finalMaterialType && finalMaterialType !== '') {
                    if (honeyStateSelect) {
                        honeyStateSelect.classList.remove('d-none');
                    }
                }
            } else if (isHoneyMaterial) {
                // إذا كانت المادة عسل ولكن لا يوجد أنواع، أظهر حقل حالة العسل مباشرة
                if (honeyStateSelect) {
                    honeyStateSelect.classList.remove('d-none');
                }
            }
        };
        
        // معالجة اختيار اسم المادة
        if (materialSelect) {
            if (defaultMaterialName && commonMaterials.includes(defaultMaterialName)) {
                materialSelect.value = defaultMaterialName;
            }
            
            materialSelect.addEventListener('change', function() {
                const selectedValue = this.value;
                
                if (selectedValue === '__custom__') {
                    // إضافة مادة جديدة
                    customMaterialInput.classList.remove('d-none');
                    customMaterialInput.required = true;
                    this.required = false;
                    this.name = '';
                    customMaterialInput.name = `raw_materials[${rawMaterialIndex}][material_name]`;
                    materialTypeSelect.classList.add('d-none');
                } else {
                    // مادة موجودة
                    customMaterialInput.classList.add('d-none');
                    customMaterialInput.required = false;
                    this.required = true;
                    customMaterialInput.name = `raw_materials[${rawMaterialIndex}][name_custom]`;
                    
                    // تحديث قائمة أنواع المادة
                    updateMaterialTypes(selectedValue, '');
                }
            });
            
            // تحديث حقل حالة العسل عند اختيار نوع المادة
            if (materialTypeSelect) {
                materialTypeSelect.addEventListener('change', function() {
                    const selectedType = this.value;
                    const selectedMaterialName = materialSelect ? materialSelect.value : '';
                    updateMaterialTypes(selectedMaterialName, selectedType);
                });
            }
            
            // تحديث قائمة الأنواع عند التحميل
            if (defaultMaterialName && commonMaterials.includes(defaultMaterialName)) {
                updateMaterialTypes(defaultMaterialName, defaultMaterialType);
            }
        }
        
        if (quantityInput && defaultQuantity) {
            quantityInput.value = Number(defaultQuantity).toFixed(3);
        }
        
        // إذا كانت المادة الافتراضية غير موجودة في القائمة، استخدم خيار "إضافة مادة جديدة"
        if (defaultName && !commonMaterials.includes(defaultMaterialName)) {
            if (materialSelect) {
                materialSelect.value = '__custom__';
                materialSelect.dispatchEvent(new Event('change'));
                if (customMaterialInput) {
                    customMaterialInput.value = defaultName;
                }
            }
        }
    }
    rawMaterialIndex++;
}

function removeRawMaterial(btn) {
    btn.closest('.raw-material-item').remove();
}

function formatTemplateQuantity(value) {
    const numericValue = Number(value);
    if (!Number.isFinite(numericValue)) {
        return '-';
    }
    try {
        return numericValue.toLocaleString(undefined, {
            minimumFractionDigits: 3,
            maximumFractionDigits: 3
        });
    } catch (formatError) {
        return numericValue.toFixed(3);
    }
}

function renderTemplateDetailsList(container, items, emptyMessage) {
    if (!container) {
        return;
    }
    container.textContent = '';
    if (!Array.isArray(items) || items.length === 0) {
        const emptyEl = document.createElement('div');
        emptyEl.className = 'details-list-empty';
        emptyEl.textContent = emptyMessage;
        container.appendChild(emptyEl);
        return;
    }

    const listEl = document.createElement('ul');
    listEl.className = 'details-list';

    items.forEach((item) => {
        const name = typeof item.name === 'string' && item.name.trim() !== ''
            ? item.name.trim()
            : (typeof item.material_name === 'string' && item.material_name.trim() !== '' ? item.material_name.trim() : (typeof item.packaging_name === 'string' ? item.packaging_name.trim() : ''));
        const quantityValue = item.quantity_per_unit ?? item.quantity ?? null;
        const unitValue = typeof item.unit === 'string' && item.unit.trim() !== ''
            ? item.unit.trim()
            : (typeof item.material_unit === 'string' && item.material_unit.trim() !== '' ? item.material_unit.trim() : 'وحدة');

        const listItem = document.createElement('li');

        const nameSpan = document.createElement('span');
        nameSpan.className = 'details-list-name';
        nameSpan.textContent = name || 'مادة غير مسماة';

        const qtySpan = document.createElement('span');
        qtySpan.className = 'details-list-qty';
        qtySpan.textContent = formatTemplateQuantity(quantityValue);

        if (qtySpan.textContent !== '-' && unitValue) {
            const unitSpan = document.createElement('span');
            unitSpan.className = 'details-list-unit';
            unitSpan.textContent = unitValue;
            qtySpan.appendChild(unitSpan);
        }

        listItem.appendChild(nameSpan);
        listItem.appendChild(qtySpan);
        listEl.appendChild(listItem);
    });

    container.appendChild(listEl);
}

function showTemplateDetails(triggerButton) {
    if (!(triggerButton instanceof HTMLElement)) {
        return;
    }
    const payloadRaw = triggerButton.getAttribute('data-template');
    if (!payloadRaw) {
        alert('لا تتوفر بيانات لعرض تفاصيل القالب.');
        return;
    }

    // التحقق من نوع التشفير (base64 أو عادي)
    const isBase64 = triggerButton.getAttribute('data-template-encoded') === 'base64';
    
    let jsonString = payloadRaw;
    
    // إذا كانت البيانات في base64، قم بفك التشفير أولاً
    if (isBase64) {
        try {
            // فك تشفير base64 مع دعم UTF-8 بشكل صحيح
            const binaryString = atob(payloadRaw);
            // تحويل binary string إلى UTF-8 string
            const bytes = new Uint8Array(binaryString.length);
            for (let i = 0; i < binaryString.length; i++) {
                bytes[i] = binaryString.charCodeAt(i);
            }
            // استخدام TextDecoder لفك تشفير UTF-8 بشكل صحيح
            const decoder = new TextDecoder('utf-8');
            jsonString = decoder.decode(bytes);
        } catch (base64Error) {
            console.error('Error decoding base64:', base64Error);
            alert('تعذر قراءة بيانات القالب.');
            return;
        }
    }

    let data;
    try {
        data = JSON.parse(jsonString);
    } catch (jsonError) {
        console.error('Template details parse error:', jsonError);
        console.error('JSON string:', jsonString);
        alert('تعذر قراءة بيانات القالب.');
        return;
    }

    const modalElement = document.getElementById('templateDetailsModal');
    if (!modalElement) {
        alert('لا يمكن فتح تفاصيل القالب حالياً.');
        return;
    }

    const titleEl = document.getElementById('templateDetailsTitle');
    if (titleEl) {
        titleEl.textContent = data.product_name || 'تفاصيل القالب';
    }

    const statusBadge = document.getElementById('templateDetailsStatusBadge');
    if (statusBadge) {
        const statusClass = (data.status === 'active') ? 'bg-success' : (data.status === 'inactive' ? 'bg-secondary' : 'bg-warning');
        statusBadge.className = 'badge rounded-pill px-3 py-2 ' + statusClass;
        statusBadge.textContent = data.status_label || 'غير محدد';
    }

    const createdAtEl = document.getElementById('templateDetailsCreatedAt');
    if (createdAtEl) {
        createdAtEl.textContent = data.created_at_label || '-';
    }

    const creatorEl = document.getElementById('templateDetailsCreator');
    if (creatorEl) {
        creatorEl.textContent = (data.creator_name && data.creator_name.trim() !== '') ? data.creator_name : 'غير محدد';
    }

    const typeEl = document.getElementById('templateDetailsType');
    if (typeEl) {
        const typeLabels = {
            honey: 'قالب عسل',
            honey_filtered: 'قالب عسل مفلتر',
            honey_raw: 'قالب عسل خام',
            olive_oil: 'قالب زيت زيتون',
            beeswax: 'قالب شمع عسل',
            derivatives: 'قالب مشتقات',
            nuts: 'قالب مكسرات',
            unified: 'قالب موحد',
            legacy: 'قالب سابق',
            general: 'قالب عام'
        };
        const templateType = typeof data.template_type === 'string' ? data.template_type : 'general';
        typeEl.textContent = typeLabels[templateType] || templateType;
    }

    const honeyEl = document.getElementById('templateDetailsHoney');
    if (honeyEl) {
        const honeyQuantity = Number(data.honey_quantity);
        if (Number.isFinite(honeyQuantity) && honeyQuantity > 0) {
            honeyEl.textContent = formatTemplateQuantity(honeyQuantity) + ' جرام';
        } else {
            honeyEl.textContent = 'لا توجد كمية عسل محددة';
        }
    }

    const notesEl = document.getElementById('templateDetailsNotes');
    if (notesEl) {
        const notes = typeof data.notes === 'string' ? data.notes.trim() : '';
        if (notes !== '') {
            notesEl.textContent = notes;
            notesEl.classList.remove('d-none');
        } else {
            notesEl.textContent = '';
            notesEl.classList.add('d-none');
        }
    }

    renderTemplateDetailsList(
        document.getElementById('templateDetailsRawMaterials'),
        data.raw_materials,
        'لا توجد مواد خام مسجلة.'
    );

    renderTemplateDetailsList(
        document.getElementById('templateDetailsPackaging'),
        data.packaging,
        'لا توجد أدوات تعبئة مسجلة.'
    );

    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
    modalInstance.show();
}

function createBatch(templateId, templateName, triggerButton) {
    const unitsInput = prompt('كم عدد العبوات المطلوب إنتاجها؟');

    if (unitsInput === null) {
        return;
    }

    const units = parseInt(unitsInput, 10);

    if (!Number.isFinite(units) || units <= 0) {
        alert('يرجى إدخال رقم صحيح أكبر من صفر.');
        return;
    }

    const btn = triggerButton instanceof HTMLElement ? triggerButton : null;
    if (btn) {
        btn.dataset.originalText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>جاري التنفيذ...';
        btn.disabled = true;
    }

    const formData = new FormData();
    formData.append('template_id', templateId);
    formData.append('units', units);

    fetch('<?php echo getRelativeUrl("create_batch.php"); ?>', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                showBatchBarcodeModal({
                    batchNumber: data.batch_number || '-',
                    productName: data.product_name || templateName || 'منتج غير معروف',
                    productionDate: data.production_date || '-',
                    expiryDate: data.expiry_date || '-',
                    quantity: Number(data.quantity) || units
                });
            } else {
                alert('❌ خطأ: ' + (data.message || 'تعذر إتمام العملية.'));
            }
        })
        .catch(error => {
            console.error('Batch creation error:', error);
            alert('⚠️ حدث خطأ أثناء الاتصال بالخادم.');
        })
        .finally(() => {
            if (btn) {
                btn.innerHTML = btn.dataset.originalText || '';
                btn.disabled = false;
                delete btn.dataset.originalText;
            }
        });
}

// دالة مساعدة لقراءة البيانات من button element أو جلبها من الخادم
function editTemplateFromButton(buttonElement) {
    if (!buttonElement || !(buttonElement instanceof HTMLElement)) {
        console.error('Invalid button element');
        return;
    }
    
    const templateId = parseInt(buttonElement.getAttribute('data-template-id') || '0', 10);
    
    if (templateId <= 0) {
        console.error('Invalid template ID:', templateId);
        alert('معرف القالب غير صالح');
        return;
    }
    
    // إظهار loading state
    const originalContent = buttonElement.innerHTML;
    buttonElement.disabled = true;
    buttonElement.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    
    // جلب البيانات من الخادم مباشرة لتجنب مشاكل encoding
    const currentUrl = new URL(window.location.href);
    const baseUrl = currentUrl.origin + currentUrl.pathname;
    const fetchUrl = baseUrl + '?page=product_templates&ajax=template_details&template_id=' + templateId;
    
    fetch(fetchUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        
        // التحقق من نوع المحتوى قبل محاولة تحويله إلى JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but received:', contentType, 'Content preview:', text.substring(0, 200));
                throw new Error('الاستجابة ليست JSON. يرجى التحقق من الاتصال بالخادم.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        buttonElement.innerHTML = originalContent;
        buttonElement.disabled = false;
        
        if (data.success && data.data) {
            // استخدام البيانات من الخادم مباشرة (بدون base64)
            editTemplate(templateId, JSON.stringify(data.data), false);
        } else {
            throw new Error(data.message || 'Failed to load template data');
        }
    })
    .catch(error => {
        console.error('Error fetching template data:', error);
        buttonElement.innerHTML = originalContent;
        buttonElement.disabled = false;
        
        // محاولة استخدام البيانات من data attribute كـ fallback
        const templateDataJson = buttonElement.getAttribute('data-template-data') || '';
        const isBase64 = buttonElement.getAttribute('data-template-encoded') === 'base64';
        
        if (templateDataJson && templateDataJson.trim() !== '') {
            console.warn('Using fallback: data from attribute');
            editTemplate(templateId, templateDataJson, isBase64);
        } else {
            alert('خطأ في جلب بيانات القالب: ' + error.message + '. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        }
    });
}

function editTemplate(templateId, templateDataJson, isBase64 = false) {
    let templateData;
    
    // التحقق من وجود البيانات
    if (!templateDataJson || templateDataJson === '' || templateDataJson === 'undefined' || templateDataJson === 'null') {
        console.error('Template data is empty or invalid:', templateDataJson);
        alert('لا توجد بيانات للقالب. يرجى تحديث القالب مرة أخرى.');
        return;
    }
    
    try {
        let jsonString = templateDataJson;
        
        // إذا كانت البيانات في base64، قم بفك التشفير أولاً
        if (isBase64) {
            try {
                // التحقق من أن البيانات ليست فارغة قبل فك التشفير
                if (!jsonString || jsonString.trim() === '') {
                    throw new Error('Base64 string is empty');
                }
                
                // فك التشفير من base64 مع دعم UTF-8 بشكل صحيح
                // PHP base64_encode يعمل بشكل صحيح مع UTF-8
                // لكن JavaScript atob() لا يدعم UTF-8 بشكل مباشر
                // الحل: استخدام escape/decodeURIComponent للتعامل مع UTF-8
                const binaryString = atob(jsonString);
                
                // تحويل binary string إلى UTF-8 بشكل صحيح
                // الطريقة الصحيحة: استخدام decodeURIComponent(escape()) أو TextDecoder
                try {
                    // الطريقة 1: استخدام TextDecoder (الأفضل)
                    if (typeof TextDecoder !== 'undefined') {
                        const bytes = new Uint8Array(binaryString.length);
                        for (let i = 0; i < binaryString.length; i++) {
                            bytes[i] = binaryString.charCodeAt(i);
                        }
                        jsonString = new TextDecoder('utf-8').decode(bytes);
                    } else {
                        // الطريقة 2: استخدام escape/decodeURIComponent (fallback)
                        let decoded = '';
                        for (let i = 0; i < binaryString.length; i++) {
                            const charCode = binaryString.charCodeAt(i);
                            if (charCode < 128) {
                                // ASCII character
                                decoded += String.fromCharCode(charCode);
                            } else {
                                // UTF-8 multi-byte character
                                decoded += '%' + ('00' + charCode.toString(16)).slice(-2);
                            }
                        }
                        jsonString = decodeURIComponent(decoded);
                    }
                } catch (utf8Error) {
                    // إذا فشلت الطريقة، استخدم الطريقة التقليدية (قد لا تعمل مع UTF-8)
                    console.warn('UTF-8 decoding failed, using binary string directly:', utf8Error);
                    jsonString = binaryString;
                }
                
                // التحقق من أن النتيجة بعد فك التشفير ليست فارغة
                if (!jsonString || jsonString.trim() === '') {
                    throw new Error('Decoded string is empty');
                }
            } catch (base64Error) {
                console.error('Error decoding base64:', base64Error);
                console.error('Base64 data (first 100 chars):', templateDataJson.substring(0, 100));
                console.error('Template ID:', templateId);
                alert('خطأ في فك تشفير بيانات القالب. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
                return;
            }
        }
        
        // التحقق من أن jsonString ليس فارغاً
        if (!jsonString || jsonString.trim() === '') {
            throw new Error('JSON string is empty');
        }
        
        // التحقق من أن JSON صالح قبل محاولة parse
        if (typeof jsonString === 'string') {
            // إزالة أي مسافات في البداية والنهاية
            jsonString = jsonString.trim();
            
            // التحقق من أن النص يبدأ بـ { أو [
            if (!jsonString.startsWith('{') && !jsonString.startsWith('[')) {
                throw new Error('Invalid JSON format: string does not start with { or [');
            }
            
            templateData = JSON.parse(jsonString);
        } else {
            templateData = jsonString;
        }
        
        // التحقق من أن templateData هو object
        if (!templateData || typeof templateData !== 'object') {
            throw new Error('Template data is not a valid object');
        }
        
    } catch (e) {
        console.error('Error parsing template data:', e);
        console.error('Template data received:', templateDataJson);
        console.error('Is Base64:', isBase64);
        console.error('Template ID:', templateId);
        console.error('Error message:', e.message);
        
        // محاولة إصلاح المشكلة: إنشاء payload بسيط من البيانات الأساسية
        console.warn('Failed to parse template data, using fallback');
        
        // استخدام payload بسيط يحتوي فقط على ID واسم المنتج
        try {
            templateData = {
                id: templateId,
                product_name: '',
                status: 'active',
                raw_materials: [],
                packaging: []
            };
            
            // محاولة ملء اسم المنتج على الأقل
            const templateCard = document.querySelector(`[data-template-id="${templateId}"]`);
            if (templateCard) {
                const productNameEl = templateCard.querySelector('.template-product-name');
                if (productNameEl) {
                    templateData.product_name = productNameEl.textContent.trim() || '';
                }
            }
            
            console.info('Using fallback template data:', templateData);
        } catch (fallbackError) {
            console.error('Fallback also failed:', fallbackError);
            alert('خطأ في قراءة بيانات القالب. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
            return;
        }
    }

    // ملء البيانات في النموذج
    document.getElementById('editTemplateId').value = templateId;
    document.getElementById('editProductName').value = templateData.product_name || '';
    
    // ملء حقل السعر
    const editUnitPriceEl = document.getElementById('editUnitPrice');
    if (editUnitPriceEl) {
        const unitPrice = templateData.unit_price || '';
        editUnitPriceEl.value = unitPrice ? parseFloat(unitPrice).toFixed(2) : '';
    }

    // تحديد أدوات التعبئة (استخدام checkboxes)
    const packagingContainer = document.getElementById('editPackagingCheckboxContainer');
    if (packagingContainer && templateData.packaging && Array.isArray(templateData.packaging)) {
        // إلغاء تحديد جميع checkboxes أولاً
        const allCheckboxes = packagingContainer.querySelectorAll('input[type="checkbox"][name="packaging_ids[]"]');
        allCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        // تحديد أدوات التعبئة الموجودة في القالب
        let selectedCount = 0;
        templateData.packaging.forEach(pkg => {
            const packagingId = pkg.packaging_material_id || pkg.id;
            if (packagingId) {
                const checkbox = packagingContainer.querySelector(`input[type="checkbox"][name="packaging_ids[]"][value="${packagingId}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                    selectedCount++;
                } else {
                    console.warn('Packaging checkbox not found for ID:', packagingId);
                }
            } else {
                console.warn('Packaging item missing ID:', pkg);
            }
        });
        
        // التحقق من أن هناك على الأقل أداة تعبئة واحدة محددة
        if (selectedCount === 0 && templateData.packaging.length > 0) {
            console.warn('No packaging items were selected despite having packaging data:', templateData.packaging);
        }
    } else {
        if (!packagingContainer) {
            console.warn('Packaging checkbox container not found');
        }
        if (!templateData.packaging || !Array.isArray(templateData.packaging)) {
            console.warn('No packaging data available:', templateData.packaging);
        }
    }

    // ملء المواد الخام
    const editContainer = document.getElementById('editRawMaterialsContainer');
    if (editContainer) {
        editContainer.innerHTML = '';
        editMaterialIndex = 0;
        
        if (templateData.raw_materials && Array.isArray(templateData.raw_materials) && templateData.raw_materials.length > 0) {
            templateData.raw_materials.forEach(material => {
                // تقسيم اسم المادة إلى اسم ونوع
                let materialName = material.material_name || material.name || '';
                let materialType = material.material_type || '';
                
                // إذا كان الاسم يحتوي على " - "، نقسمه
                if (materialName && materialName.includes(' - ') && !materialType) {
                    const parts = materialName.split(' - ', 2);
                    if (parts.length === 2) {
                        materialName = parts[0].trim();
                        materialType = parts[1].trim();
                    }
                }
                // إذا كان الاسم يبدأ بـ "عسل "، نقسمه
                else if (materialName && materialName.startsWith('عسل ') && materialName.length > 5 && !materialType) {
                    const parts = materialName.split(' ', 2);
                    if (parts.length === 2) {
                        materialName = parts[0]; // "عسل"
                        materialType = parts[1]; // النوع
                    }
                }
                
                // بناء اسم كامل للتوافق مع الكود القديم
                const fullName = materialType !== '' ? materialName + ' - ' + materialType : materialName;
                
                // استخراج حالة العسل من material_type
                let honeyState = '';
                if (material.material_type) {
                    const matType = String(material.material_type).toLowerCase();
                    if (matType === 'honey_raw') {
                        honeyState = 'raw';
                    } else if (matType === 'honey_filtered') {
                        honeyState = 'filtered';
                    }
                }
                
                addEditRawMaterial({
                    name: fullName,
                    material_name: materialName,
                    material_type: materialType,
                    quantity: material.quantity_per_unit || material.quantity || '',
                    unit: material.unit || 'كيلوجرام',
                    honey_state: honeyState,
                    material_type_saved: material.material_type || ''
                });
            });
        } else {
            addEditRawMaterial({ name: 'عسل', unit: 'كيلوجرام' });
        }
    }

    // فتح النموذج
    const modal = new bootstrap.Modal(document.getElementById('editTemplateModal'));
    modal.show();
}

let editMaterialIndex = 0;

function addEditRawMaterial(defaults = {}) {
    const container = document.getElementById('editRawMaterialsContainer');
    if (!container) {
        return;
    }
    const defaultName = typeof defaults.name === 'string' ? defaults.name : '';
    const defaultQuantity = typeof defaults.quantity === 'number' ? defaults.quantity : '';
    const defaultUnit = typeof defaults.unit === 'string' ? defaults.unit : 'كيلوجرام';
    let defaultHoneyState = defaults.honey_state || '';
    
    // استخدام material_name إذا كان موجوداً، وإلا استخدام الاسم الكامل
    let defaultMaterialName = defaults.material_name || '';
    let defaultMaterialType = defaults.material_type || '';
    
    // إذا لم يتم تحديد material_name، استخرجها من الاسم الكامل
    if (!defaultMaterialName && defaultName) {
        if (defaultName.includes(' - ')) {
            const parts = defaultName.split(' - ', 2);
            if (parts.length === 2) {
                defaultMaterialName = parts[0].trim(); // "عسل"
                if (!defaultMaterialType) {
                    defaultMaterialType = parts[1].trim(); // "حبة البركة" مثلاً
                }
            } else {
                defaultMaterialName = defaultName;
            }
        } else if (defaultName.startsWith('عسل ') && defaultName.length > 5) {
            const parts = defaultName.split(' ', 2);
            if (parts.length === 2) {
                defaultMaterialName = parts[0]; // "عسل"
                if (!defaultMaterialType) {
                    defaultMaterialType = parts[1]; // "حبة البركة" مثلاً
                }
            } else {
                defaultMaterialName = defaultName;
            }
        } else {
            defaultMaterialName = defaultName;
        }
    }
    
    // استخراج حالة العسل من material_type_saved إذا كان موجوداً
    if (!defaultHoneyState && defaults.material_type_saved) {
        const matType = String(defaults.material_type_saved).toLowerCase();
        if (matType === 'honey_raw') {
            defaultHoneyState = 'raw';
        } else if (matType === 'honey_filtered') {
            defaultHoneyState = 'filtered';
        }
    }
    
    // بناء خيارات القائمة المنسدلة
    const materialOptions = commonMaterials.map(material => {
        const selected = material === defaultMaterialName ? 'selected' : '';
        return `<option value="${material}" ${selected}>${material}</option>`;
    }).join('');
    
    const materialHtml = `
        <div class="raw-material-item mb-2 border p-2 rounded bg-light">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">اسم المادة <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm material-name-select" 
                            name="raw_materials[${editMaterialIndex}][material_name]" 
                            data-material-index="${editMaterialIndex}" 
                            data-material-name-select="${editMaterialIndex}"
                            required>
                        <option value="">-- اختر المادة --</option>
                        ${materialOptions}
                        <option value="__custom__">-- إضافة مادة جديدة --</option>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1 d-none" 
                           name="raw_materials[${editMaterialIndex}][name_custom]" 
                           id="edit_custom_material_${editMaterialIndex}" 
                           placeholder="أدخل اسم المادة الجديدة">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">نوع المادة</label>
                    <select class="form-select form-select-sm material-type-select" 
                            name="raw_materials[${editMaterialIndex}][material_type]" 
                            id="edit_material_type_${editMaterialIndex}"
                            data-material-type-select="${editMaterialIndex}">
                        <option value="">-- لا يوجد نوع --</option>
                    </select>
                    <input type="text" class="form-control form-control-sm mt-1 d-none" 
                           name="raw_materials[${editMaterialIndex}][type_custom]" 
                           id="edit_custom_type_${editMaterialIndex}" 
                           placeholder="أدخل نوع المادة">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">حالة العسل</label>
                    <select class="form-select form-select-sm honey-state-select" 
                            name="raw_materials[${editMaterialIndex}][honey_state]" 
                            id="edit_honey_state_${editMaterialIndex}"
                            data-honey-state-select="${editMaterialIndex}">
                        <option value="">-- لا ينطبق --</option>
                        <option value="raw" ${defaultHoneyState === 'raw' ? 'selected' : ''}>خام</option>
                        <option value="filtered" ${defaultHoneyState === 'filtered' ? 'selected' : ''}>مصفى</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الكمية <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" name="raw_materials[${editMaterialIndex}][quantity]" 
                           step="0.001" min="0.001" placeholder="0.000" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الوحدة</label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${editMaterialIndex}][unit]" 
                           value="كيلوجرام" readonly style="background-color: #e9ecef;">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeRawMaterial(this)">
                        <i class="bi bi-trash"></i> حذف
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', materialHtml);
    const newItem = container.lastElementChild;
    if (newItem) {
        const materialSelect = newItem.querySelector(`select[data-material-name-select="${editMaterialIndex}"]`);
        const materialTypeSelect = newItem.querySelector(`select[data-material-type-select="${editMaterialIndex}"]`);
        const customMaterialInput = newItem.querySelector(`#edit_custom_material_${editMaterialIndex}`);
        const customTypeInput = newItem.querySelector(`#edit_custom_type_${editMaterialIndex}`);
        const quantityInput = newItem.querySelector(`input[name="raw_materials[${editMaterialIndex}][quantity]"]`);
        const honeyStateSelect = newItem.querySelector(`select[data-honey-state-select="${editMaterialIndex}"]`);
        
        // دالة لتحديث قائمة أنواع المادة وحقل حالة العسل
        const updateMaterialTypes = function(selectedMaterialName, selectedMaterialType) {
            if (!materialTypeSelect) return;
            
            // مسح القائمة الحالية
            materialTypeSelect.innerHTML = '<option value="">-- لا يوجد نوع --</option>';
            materialTypeSelect.classList.add('d-none');
            customTypeInput.classList.add('d-none');
            
            // التحقق من وجود قيمة حالة عسل محملة بالفعل
            const hasHoneyStateValue = honeyStateSelect && honeyStateSelect.value && honeyStateSelect.value !== '';
            
            // إخفاء حقل حالة العسل افتراضياً (ما لم يكن لدينا قيمة محملة)
            if (honeyStateSelect && !hasHoneyStateValue) {
                honeyStateSelect.classList.add('d-none');
            }
            
            if (!selectedMaterialName || selectedMaterialName === '' || selectedMaterialName === '__custom__') {
                return;
            }
            
            // التحقق من أن المادة هي عسل (وليس شمع عسل)
            // يجب أن تكون المادة "عسل" فقط وليس "شمع عسل"
            const isHoneyMaterial = (selectedMaterialName === 'عسل' || selectedMaterialName.toLowerCase() === 'honey') && 
                                   !selectedMaterialName.includes('شمع') && 
                                   !selectedMaterialName.toLowerCase().includes('beeswax');
            
            const materialData = rawMaterialsData[selectedMaterialName];
            
            // التحقق من أن المادة هي مشتقات وتحديث الوحدة
            const isDerivativesMaterial = selectedMaterialName === 'مشتقات' || 
                                         (materialData && materialData.material_type === 'derivatives');
            
            // تحديث الوحدة للمشتقات لتكون "كيلوجرام" (الوزن)
            const unitInput = newItem.querySelector(`input[name="raw_materials[${editMaterialIndex}][unit]"]`);
            if (unitInput) {
                if (isDerivativesMaterial) {
                    unitInput.value = 'كيلوجرام';
                } else {
                    unitInput.value = defaultUnit || 'كيلوجرام';
                }
            }
            if (materialData && materialData.has_types && materialData.types && materialData.types.length > 0) {
                // إظهار القائمة المنسدلة للأنواع
                materialTypeSelect.classList.remove('d-none');
                
                materialData.types.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type;
                    option.textContent = type;
                    if (type === defaultMaterialType || type === selectedMaterialType) {
                        option.selected = true;
                    }
                    materialTypeSelect.appendChild(option);
                });
                
                // إذا كانت المادة عسل ونوع المادة محدد أو لدينا حالة عسل محملة، أظهر حقل حالة العسل
                const finalMaterialType = selectedMaterialType || materialTypeSelect.value;
                if (isHoneyMaterial && (finalMaterialType && finalMaterialType !== '' || hasHoneyStateValue)) {
                    if (honeyStateSelect) {
                        honeyStateSelect.classList.remove('d-none');
                    }
                }
            } else if (isHoneyMaterial) {
                // إذا كانت المادة عسل ولكن لا يوجد أنواع، أظهر حقل حالة العسل مباشرة
                if (honeyStateSelect) {
                    honeyStateSelect.classList.remove('d-none');
                }
            }
        };
        
        // معالجة اختيار اسم المادة
        if (materialSelect) {
            if (defaultMaterialName && commonMaterials.includes(defaultMaterialName)) {
                materialSelect.value = defaultMaterialName;
            }
            
            materialSelect.addEventListener('change', function() {
                const selectedValue = this.value;
                
                if (selectedValue === '__custom__') {
                    // إضافة مادة جديدة
                    customMaterialInput.classList.remove('d-none');
                    customMaterialInput.required = true;
                    this.required = false;
                    this.name = '';
                    customMaterialInput.name = `raw_materials[${editMaterialIndex}][material_name]`;
                    materialTypeSelect.classList.add('d-none');
                } else {
                    // مادة موجودة
                    customMaterialInput.classList.add('d-none');
                    customMaterialInput.required = false;
                    this.required = true;
                    customMaterialInput.name = `raw_materials[${editMaterialIndex}][name_custom]`;
                    
                    // تحديث قائمة أنواع المادة
                    updateMaterialTypes(selectedValue);
                }
            });
            
            // تحديث قائمة الأنواع عند التحميل
            if (defaultMaterialName && commonMaterials.includes(defaultMaterialName)) {
                updateMaterialTypes(defaultMaterialName, defaultMaterialType);
                
                // إذا كانت المادة عسل ولدينا حالة عسل محددة، أظهر الحقل
                // التحقق من أن المادة هي عسل (وليس شمع عسل)
                const isHoneyMaterial = (defaultMaterialName === 'عسل' || defaultMaterialName.toLowerCase() === 'honey') && 
                                       !defaultMaterialName.includes('شمع') && 
                                       !defaultMaterialName.toLowerCase().includes('beeswax');
                if (isHoneyMaterial && defaultHoneyState) {
                    if (honeyStateSelect) {
                        honeyStateSelect.classList.remove('d-none');
                    }
                }
            } else if (defaultMaterialName) {
                // إذا كانت المادة عسل حتى لو لم تكن في القائمة، أظهر حقل حالة العسل
                // التحقق من أن المادة هي عسل (وليس شمع عسل)
                const isHoneyMaterial = (defaultMaterialName === 'عسل' || defaultMaterialName.toLowerCase() === 'honey') && 
                                       !defaultMaterialName.includes('شمع') && 
                                       !defaultMaterialName.toLowerCase().includes('beeswax');
                if (isHoneyMaterial && defaultHoneyState) {
                    if (honeyStateSelect) {
                        honeyStateSelect.classList.remove('d-none');
                    }
                }
            }
        }
        
        if (quantityInput && defaultQuantity) {
            quantityInput.value = Number(defaultQuantity).toFixed(3);
        }
        
        // إذا كانت المادة الافتراضية غير موجودة في القائمة، استخدم خيار "إضافة مادة جديدة"
        if (defaultName && !commonMaterials.includes(defaultMaterialName)) {
            if (materialSelect) {
                materialSelect.value = '__custom__';
                materialSelect.dispatchEvent(new Event('change'));
                if (customMaterialInput) {
                    customMaterialInput.value = defaultMaterialName || defaultName;
                }
                
                // إذا كانت المادة عسل، أظهر حقل حالة العسل
                // التحقق من أن المادة هي عسل (وليس شمع عسل)
                const materialNameForCheck = defaultMaterialName || defaultName;
                const isHoneyMaterial = (materialNameForCheck === 'عسل' || materialNameForCheck.toLowerCase() === 'honey') && 
                                       !materialNameForCheck.includes('شمع') && 
                                       !materialNameForCheck.toLowerCase().includes('beeswax');
                if (isHoneyMaterial && defaultHoneyState) {
                    if (honeyStateSelect) {
                        honeyStateSelect.classList.remove('d-none');
                    }
                }
            }
        }
    }
    editMaterialIndex++;
}

// إعادة تعيين الفهرس عند إغلاق modal التعديل
document.getElementById('editTemplateModal')?.addEventListener('hidden.bs.modal', function () {
    const container = document.getElementById('editRawMaterialsContainer');
    if (container) {
        container.innerHTML = '';
    }
    editMaterialIndex = 0;
});

function deleteTemplate(templateId, templateName) {
    if (confirm('هل أنت متأكد من حذف قالب "' + templateName + '"؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_template">
            <input type="hidden" name="template_id" value="${templateId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// منع إرسال النموذج إذا لم يتم اختيار أدوات تعبئة
document.getElementById('createTemplateForm')?.addEventListener('submit', function(e) {
    const packagingSelect = document.getElementById('packagingSelect');
    if (packagingSelect && packagingSelect.selectedOptions.length === 0) {
        e.preventDefault();
        alert('يرجى اختيار أداة تعبئة واحدة على الأقل');
        return false;
    }
});

// منع إرسال النموذج إذا لم يتم اختيار أدوات تعبئة (للتعديل)
document.getElementById('editTemplateForm')?.addEventListener('submit', function(e) {
    const editPackagingContainer = document.getElementById('editPackagingCheckboxContainer');
    const checkedEditPackaging = editPackagingContainer ? editPackagingContainer.querySelectorAll('input[type="checkbox"]:checked') : [];
    if (checkedEditPackaging.length === 0) {
        e.preventDefault();
        alert('يرجى اختيار أداة تعبئة واحدة على الأقل');
        return false;
    }
});

function showBatchBarcodeModal(batchData) {
    const modalElement = document.getElementById('batchBarcodeModal');
    if (!modalElement) {
        alert('تم إنشاء التشغيله بنجاح. رقم التشغيله: ' + (batchData.batchNumber || '-'));
        return;
    }

    const numberEl = document.getElementById('batchBarcodeNumber');
    const productEl = document.getElementById('batchBarcodeProduct');
    const prodDateEl = document.getElementById('batchBarcodeProductionDate');
    const expDateEl = document.getElementById('batchBarcodeExpiryDate');
    const quantityInput = document.getElementById('batchBarcodeQuantityInput');
    const canvasEl = document.getElementById('batchBarcodeCanvas');

    if (numberEl) {
        numberEl.textContent = batchData.batchNumber || '-';
    }
    if (productEl) {
        productEl.textContent = batchData.productName || '-';
    }
    if (prodDateEl) {
        prodDateEl.textContent = batchData.productionDate || '-';
    }
    if (expDateEl) {
        expDateEl.textContent = batchData.expiryDate || '-';
    }
    if (quantityInput) {
        const defaultQuantity = Math.max(1, parseInt(batchData.quantity, 10) || 1);
        quantityInput.value = defaultQuantity;
    }

    if (canvasEl && typeof JsBarcode !== 'undefined') {
        try {
            const context = canvasEl.getContext('2d');
            if (context) {
                context.clearRect(0, 0, canvasEl.width || 0, canvasEl.height || 0);
            }
            JsBarcode(canvasEl, batchData.batchNumber || 'UNKNOWN', {
                format: 'CODE128',
                displayValue: true,
                fontSize: 14,
                lineColor: '#1f2937',
                width: 2,
                height: 80,
                margin: 10
            });
        } catch (barcodeError) {
            console.error('Barcode render error:', barcodeError);
        }
    }

    lastCreatedBatchInfo = {
        number: batchData.batchNumber || '',
        product: batchData.productName || '',
        productionDate: batchData.productionDate || '',
        expiryDate: batchData.expiryDate || '',
    };

    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
    modalInstance.show();
}

function printSingleBatchBarcode() {
    if (!lastCreatedBatchInfo || !lastCreatedBatchInfo.number) {
        alert('لا يوجد باركود متاح للطباعة حالياً.');
        return;
    }
    const url = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(lastCreatedBatchInfo.number)}&quantity=1&format=single&print=1`;
    if (typeof window.openInAppModal === 'function') {
        window.openInAppModal(url, { opener: document.activeElement instanceof Element ? document.activeElement : null });
    } else {
        window.open(url, '_blank', 'noopener');
    }
}

function printMultipleBatchBarcodes() {
    if (!lastCreatedBatchInfo || !lastCreatedBatchInfo.number) {
        alert('لا يوجد باركود متاح للطباعة حالياً.');
        return;
    }
    const quantityInput = document.getElementById('batchBarcodeQuantityInput');
    const quantity = quantityInput ? Math.max(1, parseInt(quantityInput.value, 10) || 1) : 1;
    const url = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(lastCreatedBatchInfo.number)}&quantity=${quantity}&format=multiple&print=1`;
    if (typeof window.openInAppModal === 'function') {
        window.openInAppModal(url, { opener: document.activeElement instanceof Element ? document.activeElement : null });
    } else {
        window.open(url, '_blank', 'noopener');
    }
}

function downloadBatchBarcodePdf() {
    if (!lastCreatedBatchInfo || !lastCreatedBatchInfo.number) {
        alert('لا يوجد باركود متاح للتحميل حالياً.');
        return;
    }
    if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
        alert('مكتبة إنشاء ملفات PDF غير متاحة حالياً. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
        return;
    }
    const quantityInput = document.getElementById('batchBarcodeQuantityInput');
    const quantity = quantityInput ? Math.max(1, parseInt(quantityInput.value, 10) || 1) : 1;
    const canvasEl = document.getElementById('batchBarcodeCanvas');
    if (!canvasEl) {
        alert('تعذر الوصول إلى صورة الباركود.');
        return;
    }

    const canvasDataUrl = canvasEl.toDataURL('image/png');
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'mm', 'A4');

    const marginX = 15;
    const marginY = 20;
    const labelWidth = 80;
    const labelHeight = 50;
    const horizontalGap = 10;
    const verticalGap = 12;
    const labelsPerRow = 2;

    let currentX = marginX;
    let currentY = marginY;
    let labelsPlaced = 0;

    for (let i = 0; i < quantity; i += 1) {
        doc.setDrawColor(200, 200, 200);
        doc.rect(currentX - 2, currentY - 8, labelWidth + 4, labelHeight + 20, 'S');

        doc.setFontSize(11);
        doc.setTextColor(30, 58, 95);
        doc.text(`رقم التشغيلة: ${lastCreatedBatchInfo.number}`, currentX, currentY);

        doc.setFontSize(10);
        doc.setTextColor(55, 65, 81);
        doc.text(`المنتج: ${lastCreatedBatchInfo.product}`, currentX, currentY + 6);

        doc.addImage(canvasDataUrl, 'PNG', currentX, currentY + 10, labelWidth, 25);

        doc.setFontSize(9);
        doc.setTextColor(75, 85, 99);
        doc.text(`الإنتاج: ${lastCreatedBatchInfo.productionDate}`, currentX, currentY + 40);
        if (lastCreatedBatchInfo.expiryDate && lastCreatedBatchInfo.expiryDate !== '-') {
            doc.text(`انتهاء: ${lastCreatedBatchInfo.expiryDate}`, currentX, currentY + 46);
        }

        labelsPlaced += 1;
        if (labelsPlaced % labelsPerRow === 0) {
            currentX = marginX;
            currentY += labelHeight + verticalGap + 20;
            if (currentY + labelHeight + marginY > doc.internal.pageSize.getHeight()) {
                doc.addPage();
                currentY = marginY;
            }
        } else {
            currentX += labelWidth + horizontalGap;
        }
    }

    doc.save(`barcode-${lastCreatedBatchInfo.number}.pdf`);
}
</script>
