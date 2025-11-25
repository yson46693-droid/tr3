<?php
/**
 * صفحة استيراد أدوات التعبئة من packaging.json
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';
$results = [];

// معالجة الاستيراد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    try {
        // إنشاء جدول packaging_materials إذا لم يكن موجوداً
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
        if (empty($tableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `packaging_materials` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `material_id` varchar(50) NOT NULL COMMENT 'معرف فريد مثل PKG-001',
                  `name` varchar(255) NOT NULL COMMENT 'اسم مأخوذ من type + specifications',
                  `type` varchar(100) NOT NULL COMMENT 'نوع الأداة مثل: عبوات زجاجية',
                  `specifications` varchar(255) DEFAULT NULL COMMENT 'المواصفات مثل: برطمان 720م دائري',
                  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
                  `unit` varchar(50) DEFAULT 'قطعة',
                  `unit_price` decimal(10,2) DEFAULT 0.00,
                  `status` enum('active','inactive') DEFAULT 'active',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `material_id` (`material_id`),
                  KEY `type` (`type`),
                  KEY `status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        // قراءة ملف packaging.json
        $jsonFile = __DIR__ . '/../../packaging.json';
        if (!file_exists($jsonFile)) {
            throw new Exception('ملف packaging.json غير موجود');
        }
        
        $jsonContent = file_get_contents($jsonFile);
        $packagingData = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('خطأ في قراءة ملف JSON: ' . json_last_error_msg());
        }
        
        $added = 0;
        $updated = 0;
        $errors = [];
        
        foreach ($packagingData as $item) {
            try {
                $materialId = $item['id'] ?? '';
                $type = $item['type'] ?? '';
                $specifications = $item['specifications'] ?? '';
                $quantity = floatval($item['quantity'] ?? 0);
                $unit = $item['unit'] ?? 'قطعة';
                
                // بناء الاسم من type + specifications
                $name = trim($type . ' - ' . $specifications);
                if (empty($name) || $name === ' - ') {
                    $name = $materialId;
                }
                
                if (empty($materialId)) {
                    $errors[] = "عنصر بدون material_id";
                    continue;
                }
                
                // التحقق من وجود السجل
                $existing = $db->queryOne(
                    "SELECT id FROM packaging_materials WHERE material_id = ?",
                    [$materialId]
                );
                
                if ($existing) {
                    // تحديث السجل الموجود
                    $db->execute(
                        "UPDATE packaging_materials 
                         SET name = ?, type = ?, specifications = ?, quantity = ?, unit = ?, updated_at = NOW()
                         WHERE material_id = ?",
                        [$name, $type, $specifications, $quantity, $unit, $materialId]
                    );
                    $updated++;
                } else {
                    // إضافة سجل جديد
                    $db->execute(
                        "INSERT INTO packaging_materials (material_id, name, type, specifications, quantity, unit, status)
                         VALUES (?, ?, ?, ?, ?, ?, 'active')",
                        [$materialId, $name, $type, $specifications, $quantity, $unit]
                    );
                    $added++;
                }
            } catch (Exception $e) {
                $errors[] = "خطأ في معالجة {$item['id']}: " . $e->getMessage();
            }
        }
        
        // حذف أدوات التعبئة من جدول products
        $deletedFromProducts = 0;
        try {
            $packagingProducts = $db->query(
                "SELECT id, name FROM products 
                 WHERE (category LIKE '%تغليف%' OR category LIKE '%packaging%' 
                        OR type LIKE '%تغليف%' OR type LIKE '%packaging%')
                 AND status = 'active'"
            );
            
            foreach ($packagingProducts as $product) {
                $db->execute(
                    "UPDATE products SET status = 'inactive' WHERE id = ?",
                    [$product['id']]
                );
                $deletedFromProducts++;
            }
        } catch (Exception $e) {
            $errors[] = "خطأ في حذف المنتجات من products: " . $e->getMessage();
        }
        
        // تسجيل العملية
        logAudit($currentUser['id'], 'import_packaging', 'packaging_materials', 0, null, [
            'added' => $added,
            'updated' => $updated,
            'deleted_from_products' => $deletedFromProducts
        ]);
        
        $results = [
            'added' => $added,
            'updated' => $updated,
            'deleted_from_products' => $deletedFromProducts,
            'errors' => $errors
        ];
        
        $success = "تم الاستيراد بنجاح! تم إضافة {$added} سجل جديد، تحديث {$updated} سجل موجود، وتعطيل {$deletedFromProducts} منتج من جدول products.";
        
    } catch (Exception $e) {
        $error = "خطأ في الاستيراد: " . $e->getMessage();
        error_log("Import packaging error: " . $e->getMessage());
    }
}

// التحقق من وجود الجدول
$tableExists = false;
$tableCount = 0;
try {
    $tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
    $tableExists = !empty($tableCheck);
    if ($tableExists) {
        $tableCount = $db->queryOne("SELECT COUNT(*) as total FROM packaging_materials")['total'] ?? 0;
    }
} catch (Exception $e) {
    // الجدول غير موجود
}

// التحقق من وجود ملف JSON
$jsonFile = __DIR__ . '/../../packaging.json';
$jsonExists = file_exists($jsonFile);
$jsonCount = 0;
if ($jsonExists) {
    $jsonContent = file_get_contents($jsonFile);
    $jsonData = json_decode($jsonContent, true);
    if (is_array($jsonData)) {
        $jsonCount = count($jsonData);
    }
}
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-upload me-2"></i>استيراد أدوات التعبئة</h2>
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
    
    <?php if (!empty($results['errors'])): ?>
        <div class="alert alert-warning">
            <strong>تحذيرات:</strong>
            <ul class="mb-0">
                <?php foreach ($results['errors'] as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- معلومات الحالة -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">ملف JSON</div>
                        <div class="h4 mb-0">
                            <?php if ($jsonExists): ?>
                                <span class="text-success"><?php echo $jsonCount; ?> عنصر</span>
                            <?php else: ?>
                                <span class="text-danger">غير موجود</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-<?php echo $jsonExists ? 'success' : 'danger'; ?>">
                        <i class="bi bi-<?php echo $jsonExists ? 'check-circle' : 'x-circle'; ?> fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">جدول packaging_materials</div>
                        <div class="h4 mb-0">
                            <?php if ($tableExists): ?>
                                <span class="text-success"><?php echo $tableCount; ?> سجل</span>
                            <?php else: ?>
                                <span class="text-warning">غير موجود</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-<?php echo $tableExists ? 'success' : 'warning'; ?>">
                        <i class="bi bi-<?php echo $tableExists ? 'check-circle' : 'exclamation-triangle'; ?> fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">الحالة</div>
                        <div class="h4 mb-0">
                            <?php if ($jsonExists && $tableExists): ?>
                                <span class="text-success">جاهز للاستيراد</span>
                            <?php elseif ($jsonExists): ?>
                                <span class="text-info">سيتم إنشاء الجدول</span>
                            <?php else: ?>
                                <span class="text-danger">ملف JSON غير موجود</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-info-circle fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- زر الاستيراد -->
<div class="card shadow-sm">
    <div class="card-body">
        <h5 class="card-title">استيراد أدوات التعبئة</h5>
        <p class="text-muted">
            سيتم استيراد جميع أدوات التعبئة من ملف <code>packaging.json</code> إلى جدول <code>packaging_materials</code>.
            <br>سيتم أيضاً تعطيل أدوات التعبئة الموجودة في جدول <code>products</code>.
        </p>
        
        <form method="POST" onsubmit="return confirm('هل أنت متأكد من الاستيراد؟ سيتم تحديث البيانات الموجودة وتعطيل المنتجات في جدول products.');">
            <input type="hidden" name="action" value="import">
            <button type="submit" class="btn btn-primary" <?php echo !$jsonExists ? 'disabled' : ''; ?>>
                <i class="bi bi-upload me-2"></i>استيراد الآن
            </button>
        </form>
    </div>
</div>

