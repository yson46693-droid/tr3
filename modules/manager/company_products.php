<?php
/**
 * صفحة منتجات الشركة - المدير
 * Company Products Page - Manager
 */

// تعيين ترميز UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_external_product') {
        $name = trim($_POST['product_name'] ?? '');
        $quantity = max(0, floatval($_POST['quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'قطعة');
        
        if ($name === '') {
            $error = 'يرجى إدخال اسم المنتج.';
        } else {
            try {
                // التأكد من وجود الأعمدة المطلوبة
                try {
                    $productTypeColumn = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                    if (empty($productTypeColumn)) {
                        $db->execute("ALTER TABLE `products` ADD COLUMN `product_type` ENUM('internal','external') DEFAULT 'internal' AFTER `category`");
                    }
                } catch (Exception $e) {
                    // العمود موجود بالفعل
                }
                
                $db->execute(
                    "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status)
                     VALUES (?, 'منتجات خارجية', 'external', ?, ?, ?, 'active')",
                    [$name, $quantity, $unit, $unitPrice]
                );
                
                $productId = $db->getLastInsertId();
                logAudit($currentUser['id'], 'create_external_product', 'product', $productId, null, [
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
                ]);
                
                $success = 'تم إضافة المنتج الخارجي بنجاح.';
            } catch (Exception $e) {
                error_log('create_external_product error: ' . $e->getMessage());
                $error = 'تعذر إضافة المنتج الخارجي. يرجى المحاولة لاحقاً.';
            }
        }
    } elseif ($action === 'update_external_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $name = trim($_POST['product_name'] ?? '');
        $quantity = max(0, floatval($_POST['quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'قطعة');
        
        if ($productId <= 0 || $name === '') {
            $error = 'بيانات غير صحيحة.';
        } else {
            try {
                $db->execute(
                    "UPDATE products SET name = ?, quantity = ?, unit = ?, unit_price = ?, updated_at = NOW()
                     WHERE id = ? AND product_type = 'external'",
                    [$name, $quantity, $unit, $unitPrice, $productId]
                );
                
                logAudit($currentUser['id'], 'update_external_product', 'product', $productId, null, [
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
                ]);
                
                $success = 'تم تحديث المنتج الخارجي بنجاح.';
            } catch (Exception $e) {
                error_log('update_external_product error: ' . $e->getMessage());
                $error = 'تعذر تحديث المنتج الخارجي. يرجى المحاولة لاحقاً.';
            }
        }
    } elseif ($action === 'delete_external_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        
        if ($productId <= 0) {
            $error = 'بيانات غير صحيحة.';
        } else {
            try {
                $db->execute(
                    "DELETE FROM products WHERE id = ? AND product_type = 'external'",
                    [$productId]
                );
                
                logAudit($currentUser['id'], 'delete_external_product', 'product', $productId, null, []);
                $success = 'تم حذف المنتج الخارجي بنجاح.';
            } catch (Exception $e) {
                error_log('delete_external_product error: ' . $e->getMessage());
                $error = 'تعذر حذف المنتج الخارجي. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

// الحصول على منتجات المصنع (من جدول finished_products - كل تشغيلة منفصلة)
$factoryProducts = [];
try {
    // التحقق من وجود جدول finished_products
    $finishedProductsTableExists = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    
    if (!empty($finishedProductsTableExists)) {
        $factoryProducts = $db->query("
            SELECT 
                base.id,
                base.batch_id,
                base.batch_number,
                base.product_id,
                base.product_name,
                base.product_category,
                base.production_date,
                base.quantity_produced,
                base.unit_price,
                base.total_price,
                CASE 
                    WHEN base.unit_price > 0 
                        THEN (base.unit_price * COALESCE(base.quantity_produced, 0))
                    WHEN base.unit_price = 0 AND base.total_price IS NOT NULL AND base.total_price > 0 
                        THEN base.total_price
                    ELSE 0
                END AS calculated_total_price,
                base.workers
            FROM (
                SELECT 
                    fp.id,
                    fp.batch_id,
                    fp.batch_number,
                    COALESCE(fp.product_id, bn.product_id) AS product_id,
                    COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name, 'غير محدد') AS product_name,
                    pr.category as product_category,
                    fp.production_date,
                    fp.quantity_produced,
                    COALESCE(
                        NULLIF(fp.unit_price, 0),
                        (SELECT pt.unit_price 
                         FROM product_templates pt 
                         WHERE pt.status = 'active' 
                           AND pt.unit_price IS NOT NULL 
                           AND pt.unit_price > 0
                           AND pt.unit_price <= 10000
                           AND (
                               -- مطابقة product_id أولاً (الأكثر دقة)
                               (
                                   COALESCE(fp.product_id, bn.product_id) IS NOT NULL 
                                   AND COALESCE(fp.product_id, bn.product_id) > 0
                                   AND pt.product_id IS NOT NULL 
                                   AND pt.product_id > 0 
                                   AND pt.product_id = COALESCE(fp.product_id, bn.product_id)
                               )
                               -- مطابقة product_name (مطابقة دقيقة أو جزئية)
                               OR (
                                   pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                               -- إذا لم يكن هناك product_id في القالب، نبحث فقط بالاسم
                               OR (
                                   (pt.product_id IS NULL OR pt.product_id = 0)
                                   AND pt.product_name IS NOT NULL 
                                   AND pt.product_name != ''
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) IS NOT NULL
                                   AND COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name) != ''
                                   AND (
                                       LOWER(TRIM(pt.product_name)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name)))
                                       OR LOWER(TRIM(pt.product_name)) LIKE CONCAT('%', LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))), '%')
                                       OR LOWER(TRIM(COALESCE(NULLIF(TRIM(fp.product_name), ''), pr.name))) LIKE CONCAT('%', LOWER(TRIM(pt.product_name)), '%')
                                   )
                               )
                           )
                         ORDER BY pt.unit_price DESC
                         LIMIT 1),
                        0
                    ) AS unit_price,
                    fp.total_price,
                    GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
                FROM finished_products fp
                LEFT JOIN batch_numbers bn ON fp.batch_number = bn.batch_number
                LEFT JOIN products pr ON COALESCE(fp.product_id, bn.product_id) = pr.id
                LEFT JOIN batch_workers bw ON fp.batch_id = bw.batch_id
                LEFT JOIN users u ON bw.employee_id = u.id
                WHERE (fp.quantity_produced IS NULL OR fp.quantity_produced > 0)
                GROUP BY fp.id
            ) AS base
            ORDER BY base.production_date DESC, base.id DESC
        ");
    }
} catch (Exception $e) {
    error_log('Error fetching factory products from finished_products: ' . $e->getMessage());
}

// الحصول على المنتجات الخارجية
$externalProducts = [];
try {
    $externalProducts = $db->query("
        SELECT 
            id,
            name,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price,
            (quantity * unit_price) as total_value,
            created_at,
            updated_at
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching external products: ' . $e->getMessage());
}

// إحصائيات
$totalFactoryProducts = count($factoryProducts);
$totalExternalProducts = count($externalProducts);
$totalExternalValue = 0;
foreach ($externalProducts as $ext) {
    $totalExternalValue += floatval($ext['total_value'] ?? 0);
}

// حساب القيمة الإجمالية لمنتجات المصنع
$totalFactoryValue = 0;
foreach ($factoryProducts as $product) {
    $totalPrice = floatval($product['calculated_total_price'] ?? 0);
    if ($totalPrice == 0) {
        $unitPrice = floatval($product['unit_price'] ?? 0);
        $quantity = floatval($product['quantity_produced'] ?? 0);
        if ($unitPrice > 0 && $quantity > 0) {
            $totalPrice = $unitPrice * $quantity;
        }
    }
    $totalFactoryValue += $totalPrice;
}
?>

<style>
.company-products-page {
    padding: 1.5rem 0;
}

.section-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: white;
    padding: 1.5rem 1.75rem;
    border-radius: 16px 16px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.15);
}

.section-header h5 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    font-size: 1.15rem;
}

.section-header h5 i {
    font-size: 1.4rem;
    opacity: 0.95;
}

.section-header .badge {
    background: rgba(255, 255, 255, 0.25);
    color: white;
    padding: 0.45rem 0.9rem;
    border-radius: 25px;
    font-size: 0.875rem;
    font-weight: 600;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.company-card {
    border: none;
    border-radius: 16px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
    margin-bottom: 2.5rem;
    overflow: hidden;
    background: #ffffff;
    transition: all 0.3s ease;
}

.company-card:hover {
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.company-card .card-body {
    padding: 2rem;
}

/* تحسين تصميم الجداول */
.company-card .dashboard-table-wrapper {
    border-radius: 12px;
    border: 1px solid rgba(226, 232, 240, 0.8);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    background: #ffffff;
}

.company-card .dashboard-table thead th {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: #ffffff;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    font-size: 0.8rem;
    padding: 1rem 1.25rem;
    border-right: 1px solid rgba(255, 255, 255, 0.15);
    white-space: nowrap;
    position: relative;
}

.company-card .dashboard-table thead th:first-child {
    padding-left: 1.5rem;
}

.company-card .dashboard-table thead th:last-child {
    border-right: none;
    padding-right: 1.5rem;
}

.company-card .dashboard-table tbody tr {
    transition: all 0.2s ease;
    border-bottom: 1px solid rgba(226, 232, 240, 0.5);
}

.company-card .dashboard-table tbody tr:hover {
    background: linear-gradient(90deg, rgba(29, 78, 216, 0.05) 0%, rgba(37, 99, 235, 0.08) 100%) !important;
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(29, 78, 216, 0.1);
}

.company-card .dashboard-table tbody tr:nth-child(even) {
    background: rgba(248, 250, 252, 0.6);
}

.company-card .dashboard-table tbody tr:nth-child(even):hover {
    background: linear-gradient(90deg, rgba(29, 78, 216, 0.08) 0%, rgba(37, 99, 235, 0.12) 100%) !important;
}

.company-card .dashboard-table tbody td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    font-size: 0.9rem;
    color: #1e293b;
    border: none;
}

.company-card .dashboard-table tbody td:first-child {
    padding-left: 1.5rem;
    font-weight: 600;
    color: #0f172a;
}

.company-card .dashboard-table tbody td:last-child {
    padding-right: 1.5rem;
}

.company-card .dashboard-table tbody td strong {
    font-weight: 600;
    color: #0f172a;
}

/* تحسين الألوان للقيم */
.company-card .dashboard-table tbody td .text-success {
    color: #059669 !important;
    font-weight: 600;
    font-size: 1rem;
}

.company-card .dashboard-table tbody td .text-primary {
    color: #1d4ed8 !important;
    font-weight: 600;
    font-size: 1rem;
}

/* إحصائيات المنتجات الخارجية */
.total-value-box {
    background: linear-gradient(135deg, rgba(5, 150, 105, 0.1) 0%, rgba(16, 185, 129, 0.15) 100%);
    border: 1px solid rgba(5, 150, 105, 0.2);
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.5rem;
}

.total-value-box .fw-bold {
    color: #0f766e;
    font-size: 1rem;
}

.total-value-box .text-success {
    color: #059669 !important;
    font-size: 1.5rem;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.btn-primary-custom {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border: none;
    color: white;
    padding: 0.65rem 1.4rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.25);
}

.btn-primary-custom:hover {
    background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(29, 78, 216, 0.35);
    color: white;
}

.btn-success-custom {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    color: white;
    padding: 0.65rem 1.4rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.25);
}

.btn-success-custom:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(5, 150, 105, 0.35);
    color: white;
}

.btn-success-custom.btn-sm {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
}

/* تحسين الأزرار في الجداول */
.company-card .btn-group-sm .btn {
    border-radius: 8px;
    font-weight: 500;
    padding: 0.4rem 0.8rem;
    font-size: 0.8rem;
    transition: all 0.2s ease;
}

.company-card .btn-outline-primary {
    border-color: #1d4ed8;
    color: #1d4ed8;
}

.company-card .btn-outline-primary:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border-color: #1d4ed8;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(29, 78, 216, 0.2);
}

.company-card .btn-outline-danger {
    border-color: #dc2626;
    color: #dc2626;
}

.company-card .btn-outline-danger:hover {
    background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
    border-color: #dc2626;
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(220, 38, 38, 0.2);
}

/* حالة فارغة */
.company-card .dashboard-table tbody tr td.text-center {
    padding: 3rem 1.5rem;
    color: #94a3b8;
    font-style: italic;
}

@media (max-width: 768px) {
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
    }
    
    .company-card .card-body {
        padding: 1.25rem;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.75rem 0.85rem;
        font-size: 0.85rem;
    }
    
    .company-card .dashboard-table thead th:first-child {
        padding-left: 1rem;
    }
    
    .company-card .dashboard-table tbody td:first-child {
        padding-left: 1rem;
    }
}

@media (max-width: 576px) {
    .section-header h5 {
        font-size: 1rem;
    }
    
    .company-card .card-body {
        padding: 1rem;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.6rem 0.7rem;
        font-size: 0.8rem;
    }
}
</style>

<div class="company-products-page">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>منتجات الشركة</h2>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- قسم منتجات المصنع -->
    <div class="card company-card mb-4">
        <div class="section-header">
            <h5>
                <i class="bi bi-building"></i>
                منتجات المصنع
            </h5>
            <span class="badge"><?php echo $totalFactoryProducts; ?> منتج</span>
        </div>
        <div class="card-body">
            <?php if (!empty($factoryProducts)): ?>
                <div class="total-value-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">القيمة الإجمالية لمنتجات المصنع:</span>
                        <span class="text-success fw-bold"><?php echo formatCurrency($totalFactoryValue); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <style>
                body {
                    font-family: 'Cairo', sans-serif;
                }

                .products-grid {
                    padding: 25px;
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
                    gap: 20px;
                }

                .product-card {
                    background: white;
                    padding: 25px;
                    border-radius: 18px;
                    box-shadow: 0px 4px 20px rgba(0,0,0,0.07);
                    border: 1px solid #e2e6f3;
                    position: relative;
                }

                .product-status {
                    position: absolute;
                    top: 15px;
                    left: 15px;
                    background: #2e89ff;
                    padding: 6px 14px;
                    border-radius: 20px;
                    color: white;
                    font-size: 12px;
                    font-weight: bold;
                }

                .product-name {
                    font-size: 18px;
                    font-weight: bold;
                    color: #0d2f66;
                    margin-bottom: 6px;
                }

                .product-batch-id {
                    color: #2767ff;
                    font-weight: bold;
                    text-decoration: none;
                }

                .product-barcode-box {
                    background: #f8faff;
                    border: 1px solid #d7e1f3;
                    padding: 15px;
                    border-radius: 12px;
                    text-align: center;
                    margin: 15px 0;
                }

                .product-barcode-id {
                    font-weight: bold;
                    margin-top: 8px;
                    color: #123c90;
                }

                .product-detail-row {
                    font-size: 14px;
                    margin-top: 5px;
                    color: #4b5772;
                    display: flex;
                    justify-content: space-between;
                }

                .product-barcode-container {
                    width: 100%;
                    min-height: 60px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                }

                .product-barcode-container svg {
                    max-width: 100%;
                    height: auto;
                }
            </style>

            <!-- تحميل مكتبة JsBarcode -->
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

            <?php if (empty($factoryProducts)): ?>
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات مصنع حالياً
                    </div>
                </div>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($factoryProducts as $product): ?>
                        <?php
                            $batchNumber = $product['batch_number'] ?? '';
                            $productName = htmlspecialchars($product['product_name'] ?? 'غير محدد');
                            $category = htmlspecialchars($product['product_category'] ?? '—');
                            $productionDate = !empty($product['production_date']) ? htmlspecialchars(formatDate($product['production_date'])) : '—';
                            $quantity = number_format((float)($product['quantity_produced'] ?? 0), 2);
                            $unitPrice = floatval($product['unit_price'] ?? 0);
                            $totalPrice = floatval($product['calculated_total_price'] ?? 0);
                            if ($totalPrice == 0 && $unitPrice > 0 && floatval($product['quantity_produced'] ?? 0) > 0) {
                                $totalPrice = $unitPrice * floatval($product['quantity_produced'] ?? 0);
                            }
                        ?>
                        <div class="product-card">
                            <div class="product-status">
                                <i class="bi bi-building me-1"></i>مصنع
                            </div>

                            <div class="product-name"><?php echo $productName; ?></div>
                            <?php if ($batchNumber && $batchNumber !== '—'): ?>
                                <a href="#" class="product-batch-id"><?php echo htmlspecialchars($batchNumber); ?></a>
                            <?php else: ?>
                                <span class="product-batch-id">—</span>
                            <?php endif; ?>

                            <div class="product-barcode-box">
                                <?php if ($batchNumber && $batchNumber !== '—'): ?>
                                    <div class="product-barcode-container" data-batch="<?php echo htmlspecialchars($batchNumber); ?>">
                                        <svg class="barcode-svg" style="width: 100%; height: 50px;"></svg>
                                    </div>
                                    <div class="product-barcode-id"><?php echo htmlspecialchars($batchNumber); ?></div>
                                <?php else: ?>
                                    <div class="product-barcode-id" style="color: #999;">لا يوجد باركود</div>
                                <?php endif; ?>
                            </div>

                            <div class="product-detail-row"><span>الفئة:</span> <span><?php echo $category; ?></span></div>
                            <div class="product-detail-row"><span>تاريخ الإنتاج:</span> <span><?php echo $productionDate; ?></span></div>
                            <div class="product-detail-row"><span>الكمية:</span> <span><strong><?php echo $quantity; ?></strong></span></div>
                            <div class="product-detail-row"><span>سعر الوحدة:</span> <span><?php echo formatCurrency($unitPrice); ?></span></div>
                            <div class="product-detail-row"><span>إجمالي القيمة:</span> <span><strong class="text-success"><?php echo formatCurrency($totalPrice); ?></strong></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- توليد الباركودات -->
                <script>
                (function() {
                    var maxRetries = 50;
                    var retryCount = 0;
                    
                    function generateAllBarcodes() {
                        if (typeof JsBarcode === 'undefined') {
                            retryCount++;
                            if (retryCount < maxRetries) {
                                setTimeout(generateAllBarcodes, 100);
                            } else {
                                console.error('JsBarcode library failed to load');
                                document.querySelectorAll('.product-barcode-container[data-batch]').forEach(function(container) {
                                    var batchNumber = container.getAttribute('data-batch');
                                    var svg = container.querySelector('svg');
                                    if (svg) {
                                        svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="14" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                                    }
                                });
                            }
                            return;
                        }
                        
                        var containers = document.querySelectorAll('.product-barcode-container[data-batch]');
                        if (containers.length === 0) {
                            return;
                        }
                        
                        containers.forEach(function(container) {
                            var batchNumber = container.getAttribute('data-batch');
                            var svg = container.querySelector('svg.barcode-svg');
                            
                            if (svg && batchNumber && batchNumber.trim() !== '') {
                                try {
                                    svg.innerHTML = '';
                                    JsBarcode(svg, batchNumber, {
                                        format: "CODE128",
                                        width: 2,
                                        height: 50,
                                        displayValue: false,
                                        margin: 5,
                                        background: "#ffffff",
                                        lineColor: "#000000"
                                    });
                                } catch (error) {
                                    console.error('Error generating barcode for ' + batchNumber + ':', error);
                                    svg.innerHTML = '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#666" font-family="Arial">' + batchNumber + '</text>';
                                }
                            }
                        });
                    }
                    
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', function() {
                            setTimeout(generateAllBarcodes, 200);
                        });
                    } else {
                        setTimeout(generateAllBarcodes, 200);
                    }
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- قسم المنتجات الخارجية -->
    <div class="card company-card">
        <div class="section-header">
            <h5>
                <i class="bi bi-cart4"></i>
                المنتجات الخارجية
            </h5>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge"><?php echo $totalExternalProducts; ?> منتج</span>
                <button type="button" class="btn btn-success-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addExternalProductModal">
                    <i class="bi bi-plus-circle me-1"></i>إضافة منتج خارجي
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($externalProducts)): ?>
                <div class="total-value-box">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">القيمة الإجمالية للمنتجات الخارجية:</span>
                        <span class="text-success fw-bold"><?php echo formatCurrency($totalExternalValue); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (empty($externalProducts)): ?>
                <div style="padding: 25px;">
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        لا توجد منتجات خارجية. قم بإضافة منتج جديد باستخدام الزر أعلاه.
                    </div>
                </div>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($externalProducts as $product): ?>
                        <?php
                            $productName = htmlspecialchars($product['name'] ?? 'غير محدد');
                            $quantity = number_format((float)($product['quantity'] ?? 0), 2);
                            $unit = htmlspecialchars($product['unit'] ?? 'قطعة');
                            $unitPrice = floatval($product['unit_price'] ?? 0);
                            $totalValue = floatval($product['total_value'] ?? 0);
                        ?>
                        <div class="product-card">
                            <div class="product-status" style="background: #10b981;">
                                <i class="bi bi-cart4 me-1"></i>خارجي
                            </div>

                            <div class="product-name"><?php echo $productName; ?></div>
                            <div style="color: #94a3b8; font-size: 13px; margin-bottom: 10px;">منتج خارجي</div>

                            <div class="product-detail-row"><span>الكمية:</span> <span><strong><?php echo $quantity; ?> <?php echo $unit; ?></strong></span></div>
                            <div class="product-detail-row"><span>سعر الوحدة:</span> <span><?php echo formatCurrency($unitPrice); ?></span></div>
                            <div class="product-detail-row"><span>الإجمالي:</span> <span><strong class="text-success"><?php echo formatCurrency($totalValue); ?></strong></span></div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button type="button" 
                                        class="btn btn-outline-primary js-edit-external" 
                                        style="flex: 1; border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-id="<?php echo $product['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                        data-quantity="<?php echo $product['quantity']; ?>"
                                        data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>"
                                        data-price="<?php echo $product['unit_price']; ?>">
                                    <i class="bi bi-pencil me-1"></i>تعديل
                                </button>
                                <button type="button" 
                                        class="btn btn-outline-danger js-delete-external" 
                                        style="flex: 1; border-radius: 10px; padding: 10px 16px; font-weight: bold; font-size: 13px;"
                                        data-id="<?php echo $product['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>">
                                    <i class="bi bi-trash me-1"></i>حذف
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal إضافة منتج خارجي -->
<div class="modal fade" id="addExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_external_product">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" value="قطعة">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة</label>
                        <input type="number" step="0.01" class="form-control" name="unit_price" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary-custom">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل منتج خارجي -->
<div class="modal fade" id="editExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل منتج خارجي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_external_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" id="edit_product_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" id="edit_quantity" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" id="edit_unit">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة</label>
                        <input type="number" step="0.01" class="form-control" name="unit_price" id="edit_unit_price" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary-custom">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal حذف منتج خارجي -->
<div class="modal fade" id="deleteExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_external_product">
                <input type="hidden" name="product_id" id="delete_product_id">
                <div class="modal-body">
                    <p>هل أنت متأكد من حذف المنتج <strong id="delete_product_name"></strong>؟</p>
                    <p class="text-danger mb-0"><small>لا يمكن التراجع عن هذه العملية.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">حذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// معالجة تعديل المنتجات الخارجية
document.querySelectorAll('.js-edit-external').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const name = this.dataset.name;
        const quantity = this.dataset.quantity;
        const unit = this.dataset.unit;
        const price = this.dataset.price;
        
        document.getElementById('edit_product_id').value = id;
        document.getElementById('edit_product_name').value = name;
        document.getElementById('edit_quantity').value = quantity;
        document.getElementById('edit_unit').value = unit;
        document.getElementById('edit_unit_price').value = price;
        
        new bootstrap.Modal(document.getElementById('editExternalProductModal')).show();
    });
});

// معالجة حذف المنتجات الخارجية
document.querySelectorAll('.js-delete-external').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const name = this.dataset.name;
        
        document.getElementById('delete_product_id').value = id;
        document.getElementById('delete_product_name').textContent = name;
        
        new bootstrap.Modal(document.getElementById('deleteExternalProductModal')).show();
    });
});
</script>

