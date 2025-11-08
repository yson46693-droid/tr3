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
              `status` enum('active','inactive') DEFAULT 'active',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`)
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
        
        if (empty($productName)) {
            $error = 'يجب إدخال اسم المنتج';
        } elseif ($honeyQuantity <= 0) {
            $error = 'يجب إدخال كمية العسل (بالجرام)';
        } elseif (empty($packagingIds)) {
            $error = 'يجب اختيار أداة تعبئة واحدة على الأقل';
        } else {
            try {
                $db->beginTransaction();
                
                // إنشاء القالب
                $result = $db->execute(
                    "INSERT INTO product_templates (product_name, honey_quantity, created_by, status) 
                     VALUES (?, ?, ?, 'active')",
                    [$productName, $honeyQuantity, $currentUser['id']]
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

// الحصول على القوالب
$templates = $db->query(
    "SELECT pt.*, 
            u.full_name as creator_name,
            (SELECT COUNT(*) FROM product_template_packaging WHERE template_id = pt.id) as packaging_count,
            (SELECT COUNT(*) FROM product_template_raw_materials WHERE template_id = pt.id) as raw_materials_count
     FROM product_templates pt
     LEFT JOIN users u ON pt.created_by = u.id
     ORDER BY pt.created_at DESC"
);

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
    
    <?php $packagingNameExpression = getColumnSelectExpression('product_template_packaging', 'packaging_name'); ?>
    <div class="row g-4">
        <?php foreach ($templates as $template): ?>
            <?php
            // الحصول على أدوات التعبئة
            $packaging = $db->query(
                "SELECT {$packagingNameExpression} FROM product_template_packaging WHERE template_id = ?",
                [$template['id']]
            );
            
            // الحصول على المواد الخام الأخرى
            $rawMaterials = $db->query(
                "SELECT material_name, quantity_per_unit, unit FROM product_template_raw_materials WHERE template_id = ?",
                [$template['id']]
            );
            ?>
            <div class="col-lg-4 col-md-6">
                <div class="card shadow-sm h-100 template-card" style="border-top: 4px solid #0d6efd; transition: transform 0.2s, box-shadow 0.2s;">
                    <div class="card-body">
                        <!-- Header -->
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="flex-grow-1">
                                <h5 class="card-title mb-1 text-primary">
                                    <i class="bi bi-box-seam me-2"></i>
                                    <?php echo htmlspecialchars($template['product_name']); ?>
                                </h5>
                                <small class="text-muted">
                                    <i class="bi bi-person me-1"></i>
                                    <?php echo htmlspecialchars($template['creator_name'] ?? 'غير محدد'); ?>
                                </small>
                            </div>
                            <span class="badge bg-<?php echo $template['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo $template['status'] === 'active' ? 'نشط' : 'غير نشط'; ?>
                            </span>
                        </div>
                        
                        <!-- كمية العسل -->
                        <div class="mb-3 p-3 bg-light rounded">
                            <div class="d-flex align-items-center">
                                <div class="flex-shrink-0">
                                    <div class="bg-warning bg-opacity-25 rounded-circle p-3">
                                        <i class="bi bi-droplet-fill text-warning fs-4"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <div class="text-muted small">كمية العسل</div>
                                    <div class="h5 mb-0 text-warning">
                                        <?php echo number_format($template['honey_quantity'], 3); ?> 
                                        <small class="fs-6">جرام</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- أدوات التعبئة -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-box-seam text-info me-2"></i>
                                <strong class="small">أدوات التعبئة:</strong>
                            </div>
                            <?php if (!empty($packaging)): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($packaging as $pkg): ?>
                                        <span class="badge bg-info text-white">
                                            <i class="bi bi-check-circle me-1"></i>
                                            <?php echo htmlspecialchars($pkg['packaging_name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">لا توجد أدوات تعبئة</span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- المواد الخام الأخرى -->
                        <?php if (!empty($rawMaterials)): ?>
                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-capsule text-secondary me-2"></i>
                                    <strong class="small">مواد خام أخرى:</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($rawMaterials as $raw): ?>
                                        <span class="badge bg-secondary text-white">
                                            <?php echo htmlspecialchars($raw['material_name']); ?>
                                            <small>
                                                (<?php echo number_format($raw['quantity_per_unit'], 2); ?> <?php echo htmlspecialchars($raw['unit']); ?>)
                                            </small>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Footer -->
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <small class="text-muted">
                                <i class="bi bi-calendar3 me-1"></i>
                                <?php echo formatDate($template['created_at']); ?>
                            </small>
                            <?php if ($currentUser['role'] === 'manager'): ?>
                                <button class="btn btn-sm btn-danger" 
                                        onclick="deleteTemplate(<?php echo $template['id']; ?>, '<?php echo htmlspecialchars($template['product_name']); ?>')"
                                        title="حذف">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.template-card {
    cursor: pointer;
}

.template-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

@media (max-width: 768px) {
    .template-card {
        margin-bottom: 1rem;
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
