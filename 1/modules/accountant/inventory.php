<?php
/**
 * صفحة إدارة المخزون للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole('accountant');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

$productTypeCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
$hasProductType = !empty($productTypeCheck);

if (!$hasProductType) {
    try {
        $db->execute("ALTER TABLE products ADD COLUMN product_type ENUM('internal','external') DEFAULT 'internal' AFTER category");
        $db->execute("UPDATE products SET product_type = 'internal' WHERE product_type IS NULL OR product_type = ''");
        $hasProductType = true;
    } catch (Exception $e) {
        error_log("Error adding product_type column: " . $e->getMessage());
    }
}

$externalChannelCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'external_channel'");
$hasExternalChannel = !empty($externalChannelCheck);

if (!$hasExternalChannel) {
    try {
        $db->execute("ALTER TABLE products ADD COLUMN external_channel ENUM('company','delegate','other') DEFAULT NULL AFTER product_type");
        $hasExternalChannel = true;
    } catch (Exception $e) {
        error_log("Error adding external_channel column: " . $e->getMessage());
    }
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_product') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = trim($_POST['unit'] ?? 'piece');
        $unitPrice = floatval($_POST['unit_price'] ?? 0);
        $minStock = floatval($_POST['min_stock'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        if (empty($name)) {
            $error = 'يجب إدخال اسم المنتج';
        } else {
            // التحقق من وجود الأعمدة قبل الإدراج
            $minStockCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'min_stock'");
            $unitCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
            
            $hasMinStock = !empty($minStockCheck);
            $hasUnit = !empty($unitCheck);
            
            // بناء الاستعلام بشكل ديناميكي
            $columns = ['name', 'category', 'quantity'];
            $values = [$name, $category, $quantity];
            $placeholders = ['?', '?', '?'];
            
            if ($hasUnit) {
                $columns[] = 'unit';
                $values[] = $unit;
                $placeholders[] = '?';
            }

            if ($hasProductType) {
                $columns[] = 'product_type';
                $values[] = 'internal';
                $placeholders[] = '?';
            }

            if ($hasExternalChannel) {
                $columns[] = 'external_channel';
                $values[] = null;
                $placeholders[] = '?';
            }
            
            $columns[] = 'unit_price';
            $values[] = $unitPrice;
            $placeholders[] = '?';
            
            if ($hasMinStock) {
                $columns[] = 'min_stock';
                $values[] = $minStock;
                $placeholders[] = '?';
            }
            
            $columns[] = 'description';
            $values[] = $description;
            $placeholders[] = '?';
            
            $columns[] = 'status';
            $values[] = 'active';
            $placeholders[] = '?';
            
            $sql = "INSERT INTO products (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $result = $db->execute($sql, $values);
            
            logAudit($currentUser['id'], 'add_product', 'product', $result['insert_id'], null, ['name' => $name]);
            
            // إرسال إشعار للمدير
            notifyManagers('منتج جديد', "تم إضافة منتج جديد: $name", 'info');
            
            $success = 'تم إضافة المنتج بنجاح';
        }
    } elseif ($action === 'update_stock') {
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $type = $_POST['type'] ?? 'add'; // add, subtract, set
        
        if ($productId <= 0) {
            $error = 'يجب اختيار منتج';
        } else {
            $product = $db->queryOne("SELECT quantity FROM products WHERE id = ? AND (product_type IS NULL OR product_type = 'internal')", [$productId]);
            if (!$product) {
                $error = 'المنتج المحدد غير موجود أو ليس من مخزون الشركة.';
            } else {
                $oldQuantity = $product['quantity'];
            
                if ($type === 'add') {
                    $newQuantity = $oldQuantity + $quantity;
                } elseif ($type === 'subtract') {
                    $newQuantity = $oldQuantity - $quantity;
                } else {
                    $newQuantity = $quantity;
                }
            
                $db->execute(
                    "UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ? AND (product_type IS NULL OR product_type = 'internal')",
                    [$newQuantity, $productId]
                );
            
                logAudit($currentUser['id'], 'update_stock', 'product', $productId, 
                         ['quantity' => $oldQuantity], ['quantity' => $newQuantity]);
            
                // التحقق من الحد الأدنى
                $columnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'min_stock'");
                if ($columnCheck) {
                    $product = $db->queryOne("SELECT min_stock, name FROM products WHERE id = ?", [$productId]);
                    if ($product && isset($product['min_stock']) && $newQuantity <= $product['min_stock']) {
                        notifyManagers('تنبيه مخزون', "انخفض مخزون {$product['name']} إلى {$newQuantity}", 'warning');
                    }
                } else {
                    // إذا لم يكن العمود موجوداً، تحقق من المخزون الصفر
                    $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$productId]);
                    if ($product && $newQuantity <= 0) {
                        notifyManagers('تنبيه مخزون', "انخفض مخزون {$product['name']} إلى {$newQuantity}", 'warning');
                    }
                }
            
                $success = 'تم تحديث المخزون بنجاح';
            }
        }
    }
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 10;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';

// التحقق من وجود الأعمدة قبل بناء الاستعلام
$minStockCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'min_stock'");
$unitCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
$hasMinStock = !empty($minStockCheck);
$hasUnit = !empty($unitCheck);

// إذا لم يكن عمود min_stock موجوداً، إضافته تلقائياً
if (!$hasMinStock) {
    try {
        $db->execute("ALTER TABLE products ADD COLUMN min_stock DECIMAL(10,2) DEFAULT 0.00 AFTER quantity");
        // تحديث المنتجات الموجودة بقيمة افتراضية
        $db->execute("UPDATE products SET min_stock = 0 WHERE min_stock IS NULL");
        $hasMinStock = true;
    } catch (Exception $e) {
        error_log("Error adding min_stock column: " . $e->getMessage());
    }
}

// إذا لم يكن عمود unit موجوداً، إضافته تلقائياً
if (!$hasUnit) {
    try {
        $db->execute("ALTER TABLE products ADD COLUMN unit VARCHAR(50) DEFAULT 'قطعة' AFTER quantity");
        // تحديث المنتجات الموجودة بقيمة افتراضية
        $db->execute("UPDATE products SET unit = 'قطعة' WHERE unit IS NULL OR unit = ''");
        $hasUnit = true;
    } catch (Exception $e) {
        error_log("Error adding unit column: " . $e->getMessage());
    }
}

$sql = "SELECT * FROM products WHERE (product_type IS NULL OR product_type = 'internal')";
$countSql = "SELECT COUNT(*) as total FROM products WHERE (product_type IS NULL OR product_type = 'internal')";
$params = [];

if (!empty($search)) {
    $sql .= " AND (name LIKE ? OR category LIKE ?)";
    $countSql .= " AND (name LIKE ? OR category LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $sql .= " AND category = ?";
    $countSql .= " AND category = ?";
    $params[] = $category;
}

// الحصول على العدد الإجمالي
$totalResult = $db->queryOne($countSql, $params);
$totalProducts = $totalResult['total'] ?? 0;
$totalPages = (int) ceil($totalProducts / $perPage);

// الحصول على المنتجات مع Pagination
$sql .= " ORDER BY name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;
$products = $db->query($sql, $params);

// الحصول على الفئات
$categories = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND (product_type IS NULL OR product_type = 'internal') ORDER BY category");

// ملخص سريع للمخزون
$inventorySummary = $db->queryOne("
    SELECT 
        COUNT(*) AS total_products,
        COALESCE(SUM(quantity), 0) AS total_quantity,
        COALESCE(SUM(quantity * unit_price), 0) AS total_value
    FROM products
    WHERE status = 'active' AND (product_type IS NULL OR product_type = 'internal')
");

$buildInventoryUrl = function(array $overrides = []) use ($search, $category) {
    $query = ['page' => 'inventory'];
    if ($search !== '') {
        $query['search'] = $search;
    }
    if ($category !== '') {
        $query['category'] = $category;
    }
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }
    return '?' . http_build_query($query);
};
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-boxes me-2"></i>إدارة المخزون</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
        <i class="bi bi-plus-circle me-2"></i>إضافة منتج
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

<!-- تنبيهات المخزون المنخفض -->
<style>
.inventory-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.inventory-summary-card {
    background: linear-gradient(135deg, rgba(30, 58, 95, 0.95), rgba(44, 82, 130, 0.88));
    color: #fff;
    border-radius: 18px;
    padding: 1.25rem;
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.18);
    position: relative;
    overflow: hidden;
}
.inventory-summary-card .label {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.85;
}
.inventory-summary-card .value {
    font-size: 1.7rem;
    font-weight: 700;
    margin-top: 0.35rem;
}
.inventory-summary-card .meta {
    margin-top: 0.4rem;
    font-size: 0.95rem;
    opacity: 0.85;
}
.inventory-summary-card i {
    position: absolute;
    bottom: 1rem;
    inset-inline-end: 1rem;
    font-size: 2.6rem;
    opacity: 0.12;
}
.inventory-search-card {
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.12);
}
.inventory-search-card .form-control,
.inventory-search-card .form-select {
    border-radius: 12px;
}
.inventory-search-card .btn {
    border-radius: 12px;
}
.inventory-table-card {
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    box-shadow: 0 18px 34px rgba(15, 23, 42, 0.12);
}
.inventory-table-card .card-header {
    border-radius: 18px 18px 0 0;
}
@media (max-width: 575.98px) {
    .inventory-summary-card {
        padding: 1rem;
    }
    .inventory-summary-card .value {
        font-size: 1.45rem;
    }
}
</style>

<div class="inventory-summary">
    <div class="inventory-summary-card">
        <span class="label">إجمالي المنتجات النشطة</span>
        <div class="value"><?php echo number_format($inventorySummary['total_products'] ?? 0); ?></div>
        <div class="meta">عدد العناصر المتاحة حالياً بالمخزون</div>
        <i class="bi bi-box-seam"></i>
    </div>
    <div class="inventory-summary-card">
        <span class="label">إجمالي الكمية</span>
        <div class="value"><?php echo number_format($inventorySummary['total_quantity'] ?? 0, 2); ?></div>
        <div class="meta">إجمالي الوحدات المتاحة بجميع الفئات</div>
        <i class="bi bi-diagram-3"></i>
    </div>
    <div class="inventory-summary-card">
        <span class="label">القيمة التقديرية</span>
        <div class="value"><?php echo formatCurrency($inventorySummary['total_value'] ?? 0); ?></div>
        <div class="meta">مجموع (الكمية × سعر الوحدة) للمنتجات النشطة</div>
        <i class="bi bi-cash-stack"></i>
    </div>
</div>

<!-- البحث والفلترة -->
<div class="card inventory-search-card mb-4">
    <div class="card-body">
        <?php
        require_once __DIR__ . '/../../includes/path_helper.php';
        $currentUrl = getRelativeUrl('dashboard/accountant.php');
        ?>
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="row g-3">
            <input type="hidden" name="page" value="inventory">
            <div class="col-md-6">
                <input type="text" class="form-control" name="search" 
                       placeholder="بحث..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-4">
                <select class="form-select" name="category">
                    <option value="">جميع الفئات</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-2"></i>بحث
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة المنتجات -->
<div class="card inventory-table-card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة المنتجات</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive dashboard-table-wrapper">
            <table class="table dashboard-table align-middle">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>الفئة</th>
                        <th>الكمية</th>
                        <th>الوحدة</th>
                        <th>السعر</th>
                        <th>الحد الأدنى</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد منتجات</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <tr class="<?php echo (isset($product['min_stock']) && $product['quantity'] <= $product['min_stock']) ? 'table-warning' : ''; ?>">
                                <td data-label="الاسم"><?php echo htmlspecialchars($product['name']); ?></td>
                                <td data-label="الفئة"><?php echo htmlspecialchars($product['category'] ?? '-'); ?></td>
                                <td data-label="الكمية">
                                    <strong><?php echo number_format($product['quantity'], 2); ?></strong>
                                </td>
                                <td data-label="الوحدة"><?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></td>
                                <td data-label="السعر"><?php echo formatCurrency($product['unit_price']); ?></td>
                                <td data-label="الحد الأدنى"><?php echo isset($product['min_stock']) ? number_format($product['min_stock'], 2) : '0.00'; ?></td>
                                <td data-label="الحالة">
                                    <span class="badge bg-<?php echo $product['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo $product['status'] === 'active' ? 'نشط' : 'غير نشط'; ?>
                                    </span>
                                </td>
                                <td data-label="الإجراءات">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-info" 
                                                onclick="updateStock(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name']); ?>')"
                                                title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                            <span class="d-none d-md-inline">تعديل</span>
                                        </button>
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
                    <a class="page-link" href="<?php echo htmlspecialchars($buildInventoryUrl(['p' => $pageNum - 1])); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildInventoryUrl(['p' => 1])); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo htmlspecialchars($buildInventoryUrl(['p' => $i])); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildInventoryUrl(['p' => $totalPages])); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo htmlspecialchars($buildInventoryUrl(['p' => $pageNum + 1])); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة منتج -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة منتج جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الفئة</label>
                        <input type="text" class="form-control" name="category" 
                               placeholder="مثل: صناديق كرتون، عبوات زجاجية">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" value="قطعة">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">سعر الوحدة</label>
                            <input type="number" step="0.01" class="form-control" name="unit_price" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الحد الأدنى للمخزون</label>
                            <input type="number" step="0.01" class="form-control" name="min_stock" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الوصف</label>
                        <textarea class="form-control" name="description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تحديث المخزون -->
<div class="modal fade" id="updateStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تحديث المخزون</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="product_id" id="updateProductId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">المنتج</label>
                        <input type="text" class="form-control" id="updateProductName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع العملية</label>
                        <select class="form-select" name="type" id="updateType">
                            <option value="add">إضافة</option>
                            <option value="subtract">خصم</option>
                            <option value="set">تعيين</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">الكمية</label>
                        <input type="number" step="0.01" class="form-control" name="quantity" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحديث</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateStock(productId, productName) {
    document.getElementById('updateProductId').value = productId;
    document.getElementById('updateProductName').value = productName;
    const modal = new bootstrap.Modal(document.getElementById('updateStockModal'));
    modal.show();
}
</script>

