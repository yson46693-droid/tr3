<?php
/**
 * صفحة قوالب المنتجات - نموذج مبسط
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}


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
        $honeyQuantity = floatval($_POST['honey_quantity'] ?? 0);
        
        // أدوات التعبئة
        $packagingIds = [];
        if (isset($_POST['packaging_ids']) && is_array($_POST['packaging_ids'])) {
            $packagingIds = array_filter(array_map('intval', $_POST['packaging_ids']));
        }
        
        // المواد الخام الأخرى (للاستخدام لاحقاً)
        $rawMaterials = [];
        if (isset($_POST['raw_materials']) && is_array($_POST['raw_materials'])) {
            foreach ($_POST['raw_materials'] as $material) {
                if (!empty($material['name']) && isset($material['quantity']) && $material['quantity'] > 0) {
                    $rawMaterials[] = [
                        'name' => trim($material['name']),
                        'quantity' => floatval($material['quantity']),
                        'unit' => trim($material['unit'] ?? 'جرام')
                    ];
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
        } elseif ($honeyQuantity <= 0) {
            $error = 'يجب إدخال كمية العسل (بالجرام)';
        } elseif (empty($packagingIds)) {
            $error = 'يجب اختيار أداة تعبئة واحدة على الأقل';
        } else {
            try {
                $db->beginTransaction();
                
                // إنشاء القالب
                $result = $db->execute(
                    "INSERT INTO product_templates (product_name, honey_quantity, created_by, status, template_type, main_supplier_id, notes, details_json) 
                     VALUES (?, ?, ?, 'active', ?, NULL, NULL, NULL)",
                    [$productName, $honeyQuantity, $currentUser['id'], 'legacy']
                );
                
                $templateId = $result['insert_id'];
                
                // إضافة أدوات التعبئة
                $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
                foreach ($packagingIds as $packagingId) {
                    $packagingName = '';
                    if (!empty($packagingTableCheck)) {
                        $packaging = $db->queryOne("SELECT name FROM packaging_materials WHERE id = ?", [$packagingId]);
                        $packagingName = $packaging['name'] ?? '';
                    }
                    
                    $db->execute(
                        "INSERT INTO product_template_packaging (template_id, packaging_material_id, packaging_name, quantity_per_unit) 
                         VALUES (?, ?, ?, 1.000)",
                        [$templateId, $packagingId, $packagingName]
                    );
                }
                
                // إضافة المواد الخام الأخرى
                foreach ($rawMaterials as $material) {
                    $db->execute(
                        "INSERT INTO product_template_raw_materials (template_id, material_name, quantity_per_unit, unit) 
                         VALUES (?, ?, ?, ?)",
                        [$templateId, $material['name'], $material['quantity'], $material['unit']]
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

foreach ($templates as &$template) {
    $templateId = (int)($template['id'] ?? 0);

    // أدوات التعبئة
    $packagingDetails = $db->query(
        "SELECT packaging_material_id, packaging_name, quantity_per_unit 
         FROM product_template_packaging 
         WHERE template_id = ?",
        [$templateId]
    );
    $template['packaging_details'] = $packagingDetails;
    $template['packaging_count'] = count($packagingDetails);

    // المواد الخام
    $rawMaterialsRows = $db->query(
        "SELECT material_name, quantity_per_unit, unit 
         FROM product_template_raw_materials 
         WHERE template_id = ?",
        [$templateId]
    );

    $materialDetails = [];
    foreach ($rawMaterialsRows as $raw) {
        $materialDetails[] = [
            'material_name' => $raw['material_name'],
            'quantity_per_unit' => (float)($raw['quantity_per_unit'] ?? 0),
            'unit' => $raw['unit'] ?? 'وحدة'
        ];
    }

    if (empty($materialDetails) && (float)($template['honey_quantity'] ?? 0) > 0) {
        $materialDetails[] = [
            'material_name' => 'عسل',
            'quantity_per_unit' => (float)$template['honey_quantity'],
            'unit' => 'جرام'
        ];
    }

    $template['material_details'] = $materialDetails;
    $template['raw_materials_count'] = count($materialDetails);
    $template['template_type'] = $template['template_type'] ?: 'general';
    $template['products_count'] = 1;
}
unset($template);

// الحصول على أدوات التعبئة
$packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$packagingMaterials = [];
if (!empty($packagingTableCheck)) {
    $packagingMaterials = $db->query(
        "SELECT id, name, type, quantity, unit FROM packaging_materials WHERE status = 'active' ORDER BY name"
    );
}

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
            $statusLabel = $template['status'] === 'active' ? 'نشط' : 'غير نشط';
            $createdAtLabel = formatDate($template['created_at']);
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
                    </div>
                    <div class="card-footer template-card-footer d-flex justify-content-between align-items-center">
                        <button type="button"
                                class="btn btn-sm btn-primary"
                                onclick="createBatch(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['product_name'], ENT_QUOTES, 'UTF-8'); ?>', this)">
                            <i class="bi bi-gear-wide-connected me-1"></i>
                            تشغيل تشغيلة
                        </button>
                        <div class="d-flex align-items-center text-muted small">
                            <i class="bi bi-calendar3 me-1"></i>
                            <?php echo $createdAtLabel; ?>
                        </div>
                        <?php if ($currentUser['role'] === 'manager'): ?>
                            <button class="btn btn-sm btn-outline-danger"
                                    onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['product_name']); ?>')"
                                    title="حذف القالب">
                                <i class="bi bi-trash"></i>
                            </button>
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
}

.template-card-footer .btn {
    flex-shrink: 0;
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
                        <label class="form-label">كمية العسل (بالجرام) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="honey_quantity" step="0.001" min="0.001" required 
                               placeholder="مثل: 720.000">
                        <small class="text-muted">كمية العسل الصافي المستخدمة في صناعة المنتج بالجرام</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">أدوات التعبئة المستخدمة <span class="text-danger">*</span></label>
                        <?php if (empty($packagingMaterials)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا توجد أدوات تعبئة متاحة. يرجى إضافة أدوات التعبئة أولاً من صفحة مخزن أدوات التعبئة.
                            </div>
                        <?php else: ?>
                            <select class="form-select" name="packaging_ids[]" multiple required size="5" id="packagingSelect">
                                <?php foreach ($packagingMaterials as $pkg): ?>
                                    <option value="<?php echo $pkg['id']; ?>">
                                        <?php echo htmlspecialchars($pkg['name']); ?>
                                        <?php if (!empty($pkg['quantity'])): ?>
                                            (المخزون: <?php echo number_format($pkg['quantity'], 2); ?> <?php echo htmlspecialchars($pkg['unit'] ?? ''); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">يمكنك اختيار أكثر من أداة تعبئة (اضغط Ctrl/Cmd للاختيار المتعدد)</small>
                        <?php endif; ?>
                    </div>
                    
                    <!-- المواد الخام الأخرى (للاستخدام لاحقاً) -->
                    <div class="mb-3">
                        <label class="form-label">مواد خام أخرى (اختياري)</label>
                        <p class="text-muted small">يمكنك إضافة مواد خام أخرى مثل المكسرات (سيتم تفعيلها لاحقاً)</p>
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

<script>
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
                const productLabel = data.product_name || templateName || 'منتج غير معروف';
                alert(
                    '✅ تم إنشاء التشغيله بنجاح\n' +
                    'رقم التشغيله: ' + (data.batch_number || '-') + '\n' +
                    'المنتج: ' + productLabel + '\n' +
                    'الكمية: ' + units + '\n' +
                    'تاريخ الإنتاج: ' + (data.production_date || '-') + '\n' +
                    'تاريخ الانتهاء: ' + (data.expiry_date || '-')
                );
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
                btn.innerHTML = btn.dataset.originalText || '<i class="bi bi-gear-wide-connected me-1"></i>تشغيل تشغيلة';
                btn.disabled = false;
                delete btn.dataset.originalText;
            }
        });
}

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
</script>
