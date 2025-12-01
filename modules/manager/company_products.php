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

requireRole(['manager', 'accountant']);

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
* {
    box-sizing: border-box;
}

.company-products-page {
    padding: 1.5rem 0;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
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
    width: 100%;
    max-width: 100%;
    flex-wrap: wrap;
    gap: 1rem;
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
    width: 100%;
    max-width: 100%;
}

.company-card:hover {
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.company-card .card-body {
    padding: 2rem;
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
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
    width: 100%;
    max-width: 100%;
    overflow: hidden;
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
    .company-products-page {
        padding: 1rem 0;
    }
    
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        width: 100%;
        max-width: 100%;
    }
    
    .section-header h5 {
        font-size: 1rem;
        word-wrap: break-word;
        width: 100%;
    }
    
    .section-header .badge {
        font-size: 0.8rem;
        padding: 0.4rem 0.8rem;
    }
    
    .company-card {
        width: 100%;
        max-width: 100%;
        margin-bottom: 1.5rem;
    }
    
    .company-card .card-body {
        padding: 1.25rem;
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .total-value-box {
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
        width: 100%;
        max-width: 100%;
    }
    
    .total-value-box .d-flex {
        flex-direction: column;
        gap: 0.5rem;
        align-items: flex-start !important;
    }
    
    .total-value-box .fw-bold {
        font-size: 0.9rem;
        word-wrap: break-word;
    }
    
    .total-value-box .text-success {
        font-size: 1.25rem !important;
    }
    
    .products-grid {
        padding: 15px;
        grid-template-columns: 1fr !important;
        gap: 15px;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box;
        margin: 0;
    }
    
    .product-card {
        padding: 20px;
        border-radius: 12px;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
        margin: 0;
        overflow: hidden;
    }
    
    .product-status {
        top: 10px;
        left: 10px;
        padding: 5px 12px;
        font-size: 11px;
    }
    
    .product-name {
        font-size: 16px;
        margin-bottom: 5px;
        word-wrap: break-word;
    }
    
    .product-batch-id {
        font-size: 13px;
        word-wrap: break-word;
    }
    
    .product-barcode-box {
        padding: 12px;
        margin: 12px 0;
    }
    
    .product-barcode-container {
        min-height: 50px;
    }
    
    .product-barcode-container svg {
        max-width: 100%;
        height: auto;
    }
    
    .product-barcode-id {
        font-size: 12px;
        margin-top: 6px;
        word-wrap: break-word;
    }
    
    .product-detail-row {
        font-size: 13px;
        margin-top: 4px;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .product-detail-row span {
        word-wrap: break-word;
    }
    
    .product-card > div[style*="display: flex"] {
        flex-direction: column;
        gap: 8px;
    }
    
    .product-card button {
        width: 100% !important;
        flex: 1 1 100% !important;
        padding: 10px 14px !important;
        font-size: 12px !important;
    }
    
    .company-card .dashboard-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        width: 100%;
        max-width: 100%;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar {
        height: 8px;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .company-card .dashboard-table-wrapper::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 4px;
    }
    
    .company-card .dashboard-table {
        min-width: 500px;
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
    .company-products-page {
        padding: 0.75rem 0;
    }
    
    .company-products-page h2 {
        font-size: 1.25rem;
        word-wrap: break-word;
    }
    
    .section-header {
        padding: 1rem 1.25rem;
        gap: 0.75rem;
    }
    
    .section-header h5 {
        font-size: 0.95rem;
    }
    
    .section-header .badge {
        font-size: 0.75rem;
        padding: 0.35rem 0.7rem;
    }
    
    .company-card .card-body {
        padding: 1rem;
    }
    
    .total-value-box {
        padding: 0.875rem 1rem;
        margin-bottom: 1rem;
    }
    
    .total-value-box .fw-bold {
        font-size: 0.85rem;
    }
    
    .total-value-box .text-success {
        font-size: 1.1rem !important;
    }
    
    .products-grid {
        padding: 12px;
        gap: 12px;
    }
    
    .product-card {
        padding: 15px;
        border-radius: 10px;
    }
    
    .product-status {
        top: 8px;
        left: 8px;
        padding: 4px 10px;
        font-size: 10px;
    }
    
    .product-name {
        font-size: 15px;
    }
    
    .product-batch-id {
        font-size: 12px;
    }
    
    .product-barcode-box {
        padding: 10px;
        margin: 10px 0;
    }
    
    .product-barcode-container {
        min-height: 45px;
    }
    
    .product-barcode-id {
        font-size: 11px;
    }
    
    .product-detail-row {
        font-size: 12px;
    }
    
    .product-card button {
        padding: 8px 12px !important;
        font-size: 11px !important;
    }
    
    .company-card .dashboard-table {
        min-width: 450px;
        font-size: 0.8rem;
    }
    
    .company-card .dashboard-table thead th,
    .company-card .dashboard-table tbody td {
        padding: 0.6rem 0.7rem;
        font-size: 0.8rem;
    }
    
    .btn-success-custom.btn-sm {
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }
}
</style>

<div class="company-products-page">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2" style="width: 100%; max-width: 100%;">
        <h2 class="mb-0" style="word-wrap: break-word; width: 100%; max-width: 100%;"><i class="bi bi-box-seam me-2 text-primary"></i>منتجات الشركة</h2>
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
                    width: 100%;
                    max-width: 100%;
                }

                .product-card {
                    background: white;
                    padding: 25px;
                    border-radius: 18px;
                    box-shadow: 0px 4px 20px rgba(0,0,0,0.07);
                    border: 1px solid #e2e6f3;
                    position: relative;
                    width: 100%;
                    max-width: 100%;
                    overflow: hidden;
                    box-sizing: border-box;
                }
                
                /* تحسين عرض البطاقات على الهواتف */
                @media (max-width: 768px) {
                    .products-grid {
                        padding: 15px;
                        grid-template-columns: 1fr;
                        gap: 15px;
                        width: 100%;
                        max-width: 100%;
                        box-sizing: border-box;
                    }
                    
                    .product-card {
                        width: 100% !important;
                        max-width: 100% !important;
                        min-width: 0 !important;
                        padding: 20px;
                        margin: 0;
                        box-sizing: border-box;
                    }
                }
                
                @media (max-width: 576px) {
                    .products-grid {
                        padding: 12px;
                        gap: 12px;
                    }
                    
                    .product-card {
                        padding: 15px;
                        border-radius: 12px;
                    }
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

                            <?php if ($batchNumber && $batchNumber !== '—'): ?>
                                <?php
                                    $viewUrl = getRelativeUrl('production.php?page=batch_numbers&batch_number=' . urlencode($batchNumber));
                                ?>
                                <div style="display: flex; gap: 10px; margin-top: 15px;">
                                    <button type="button" 
                                            class="btn-view js-batch-details" 
                                            style="border: none; cursor: pointer; flex: 1; background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch="<?php echo htmlspecialchars($batchNumber); ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-view-url="<?php echo htmlspecialchars($viewUrl); ?>">
                                        <i class="bi bi-eye me-1"></i>عرض التفاصيل
                                    </button>
                                    <button type="button" 
                                            class="btn-view js-print-barcode" 
                                            style="border: none; cursor: pointer; flex: 1; background: #28a745; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;"
                                            data-batch="<?php echo htmlspecialchars($batchNumber); ?>"
                                            data-product="<?php echo htmlspecialchars($productName); ?>"
                                            data-quantity="<?php echo htmlspecialchars($quantity); ?>">
                                        <i class="bi bi-printer me-1"></i>طباعة الباركود
                                    </button>
                                </div>
                            <?php else: ?>
                                <span class="btn-view" style="opacity: 0.5; cursor: not-allowed; display: inline-block; margin-top: 15px; background: #0c2c80; color: white; padding: 10px 16px; border-radius: 10px; font-weight: bold; font-size: 13px;">
                                    <i class="bi bi-eye me-1"></i>عرض التفاصيل
                                </span>
                            <?php endif; ?>
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

<!-- Modal طباعة الباركود -->
<div class="modal fade" id="printBarcodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>طباعة الباركود</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    جاهز للطباعة
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
                    <i class="bi bi-printer me-2"></i>طباعة
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal تفاصيل التشغيلة -->
<div class="modal fade" id="batchDetailsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل التشغيلة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="batchDetailsLoading" class="d-flex justify-content-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جارٍ التحميل...</span>
                    </div>
                </div>
                <div id="batchDetailsError" class="alert alert-danger d-none" role="alert"></div>
                <div id="batchDetailsContent" class="d-none">
                    <div id="batchSummarySection" class="mb-4"></div>
                    <div id="batchMaterialsSection" class="mb-4"></div>
                    <div id="batchRawMaterialsSection" class="mb-4"></div>
                    <div id="batchWorkersSection" class="mb-0"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
// تهيئة المتغيرات والدوال
const PRINT_BARCODE_URL = <?php echo json_encode(getRelativeUrl('print_barcode.php')); ?>;
if (typeof window !== 'undefined') {
    window.PRINT_BARCODE_URL = PRINT_BARCODE_URL;
}

function showBarcodePrintModal(batchNumber, productName, defaultQuantity) {
    const modalElement = document.getElementById('printBarcodesModal');
    if (!modalElement) {
        console.error('Modal printBarcodesModal not found');
        const quantity = defaultQuantity > 0 ? defaultQuantity : 1;
        const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
        window.open(fallbackUrl, '_blank');
        return;
    }
    
    const quantity = defaultQuantity > 0 ? defaultQuantity : 1;
    window.batchNumbersToPrint = [batchNumber];

    const productNameInput = document.getElementById('barcode_product_name');
    if (productNameInput) {
        productNameInput.value = productName || '';
    }

    const quantityInput = document.getElementById('barcode_print_quantity');
    if (quantityInput) {
        quantityInput.value = quantity;
    }

    const batchListContainer = document.getElementById('batch_numbers_list');
    if (batchListContainer) {
        batchListContainer.innerHTML = `
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                <strong>رقم التشغيلة:</strong> ${batchNumber}<br>
                <small>ستتم طباعة نفس رقم التشغيلة بعدد ${quantity} باركود</small>
            </div>
        `;
    }

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    } else {
        const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
        window.open(fallbackUrl, '_blank');
    }
}

function printBarcodes() {
    const batchNumbers = window.batchNumbersToPrint || [];
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيلة للطباعة');
        return;
    }

    const quantityInput = document.getElementById('barcode_print_quantity');
    const printQuantity = quantityInput ? parseInt(quantityInput.value, 10) : 1;
    
    if (!printQuantity || printQuantity < 1) {
        alert('يرجى إدخال عدد صحيح للطباعة');
        return;
    }

    const batchNumber = batchNumbers[0];
    const printUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${printQuantity}&print=1`;
    window.open(printUrl, '_blank');
}

window.showBarcodePrintModal = showBarcodePrintModal;
window.printBarcodes = printBarcodes;

// وظيفة عرض تفاصيل التشغيلة
const batchDetailsEndpoint = <?php echo json_encode(getRelativeUrl('api/production/get_batch_details.php')); ?>;
let batchDetailsIsLoading = false;

// تخزين مؤقت (Cache) لتفاصيل التشغيلات
const batchDetailsCache = new Map();
const CACHE_DURATION = 10 * 60 * 1000; // 10 دقائق بالملي ثانية
const MAX_CACHE_SIZE = 50; // أقصى عدد من التشغيلات في cache

/**
 * تنظيف cache من البيانات المنتهية الصلاحية
 */
function cleanBatchDetailsCache() {
    const now = Date.now();
    const keysToDelete = [];
    
    batchDetailsCache.forEach((value, key) => {
        if (now - value.timestamp > CACHE_DURATION) {
            keysToDelete.push(key);
        }
    });
    
    keysToDelete.forEach(key => batchDetailsCache.delete(key));
    
    // إذا كان حجم cache كبيراً، احذف أقدم العناصر
    if (batchDetailsCache.size > MAX_CACHE_SIZE) {
        const entries = Array.from(batchDetailsCache.entries());
        entries.sort((a, b) => a[1].timestamp - b[1].timestamp);
        
        const toRemove = entries.slice(0, entries.length - MAX_CACHE_SIZE);
        toRemove.forEach(([key]) => batchDetailsCache.delete(key));
    }
}

/**
 * الحصول على تفاصيل التشغيلة من cache
 */
function getBatchDetailsFromCache(batchNumber) {
    cleanBatchDetailsCache();
    const cached = batchDetailsCache.get(batchNumber);
    
    if (cached) {
        const now = Date.now();
        if (now - cached.timestamp < CACHE_DURATION) {
            return cached.data;
        } else {
            // حذف البيانات المنتهية الصلاحية
            batchDetailsCache.delete(batchNumber);
        }
    }
    
    return null;
}

/**
 * حفظ تفاصيل التشغيلة في cache
 */
function setBatchDetailsInCache(batchNumber, data) {
    cleanBatchDetailsCache();
    batchDetailsCache.set(batchNumber, {
        data: data,
        timestamp: Date.now()
    });
}

function showBatchDetailsModal(batchNumber, productName, retryCount = 0) {
    if (!batchNumber || typeof batchNumber !== 'string' || batchNumber.trim() === '') {
        console.error('Invalid batch number');
        return;
    }
    
    if (batchDetailsIsLoading) {
        return;
    }
    
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Modal === 'undefined') {
        alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
        return;
    }
    
    const modalElement = document.getElementById('batchDetailsModal');
    if (!modalElement) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كان النموذج غير موجود
        if (retryCount < 3) {
            setTimeout(function() {
                showBatchDetailsModal(batchNumber, productName, retryCount + 1);
            }, 200);
            return;
        }
        console.error('batchDetailsModal not found after retries');
        return;
    }
    
    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
    const loader = modalElement.querySelector('#batchDetailsLoading');
    const errorAlert = modalElement.querySelector('#batchDetailsError');
    const contentWrapper = modalElement.querySelector('#batchDetailsContent');
    const modalTitle = modalElement.querySelector('.modal-title');
    
    // التحقق من وجود العناصر المطلوبة
    // loader و contentWrapper ضروريان، errorAlert اختياري
    if (!loader || !contentWrapper) {
        // محاولة مرة أخرى بعد فترة قصيرة إذا كانت العناصر غير موجودة
        if (retryCount < 3) {
            setTimeout(function() {
                showBatchDetailsModal(batchNumber, productName, retryCount + 1);
            }, 200);
            return;
        }
        console.error('Required modal elements not found after retries', {
            loader: !!loader,
            errorAlert: !!errorAlert,
            contentWrapper: !!contentWrapper
        });
        alert('تعذر فتح تفاصيل التشغيلة. يرجى تحديث الصفحة.');
        return;
    }
    
    // إنشاء errorAlert ديناميكياً إذا لم يكن موجوداً
    if (!errorAlert) {
        const modalBody = modalElement.querySelector('.modal-body');
        if (modalBody) {
            const errorDivElement = document.createElement('div');
            errorDivElement.className = 'alert alert-danger d-none';
            errorDivElement.id = 'batchDetailsError';
            errorDivElement.setAttribute('role', 'alert');
            // إدراجه بعد loader
            if (loader.nextSibling) {
                modalBody.insertBefore(errorDivElement, loader.nextSibling);
            } else {
                modalBody.appendChild(errorDivElement);
            }
        }
    }
    
    const finalErrorAlert = modalElement.querySelector('#batchDetailsError');
    
    if (modalTitle) {
        modalTitle.textContent = productName ? `تفاصيل التشغيلة - ${productName}` : 'تفاصيل التشغيلة';
    }
    
    // التحقق من وجود البيانات في cache
    const cachedData = getBatchDetailsFromCache(batchNumber);
    if (cachedData) {
        // استخدام البيانات من cache مباشرة
        loader.classList.add('d-none');
        if (finalErrorAlert) finalErrorAlert.classList.add('d-none');
        renderBatchDetails(cachedData);
        if (contentWrapper) contentWrapper.classList.remove('d-none');
        modalInstance.show();
        return;
    }
    
    // إذا لم تكن البيانات موجودة في cache، جلبها من الخادم
    loader.classList.remove('d-none');
    if (finalErrorAlert) finalErrorAlert.classList.add('d-none');
    contentWrapper.classList.add('d-none');
    batchDetailsIsLoading = true;
    
    modalInstance.show();
    
    fetch(batchDetailsEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_number: batchNumber })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (loader) loader.classList.add('d-none');
        batchDetailsIsLoading = false;
        
        const errorAlertEl = modalElement.querySelector('#batchDetailsError');
        
        if (data.success && data.batch) {
            // حفظ البيانات في cache
            setBatchDetailsInCache(batchNumber, data.batch);
            
            renderBatchDetails(data.batch);
            if (contentWrapper) contentWrapper.classList.remove('d-none');
            if (errorAlertEl) errorAlertEl.classList.add('d-none');
        } else {
            if (errorAlertEl) {
                errorAlertEl.textContent = data.message || 'تعذر تحميل تفاصيل التشغيلة';
                errorAlertEl.classList.remove('d-none');
            }
            if (contentWrapper) contentWrapper.classList.add('d-none');
        }
    })
    .catch(error => {
        if (loader) loader.classList.add('d-none');
        const errorAlertEl = modalElement.querySelector('#batchDetailsError');
        if (errorAlertEl) {
            errorAlertEl.textContent = 'حدث خطأ أثناء تحميل التفاصيل: ' + (error.message || 'خطأ غير معروف');
            errorAlertEl.classList.remove('d-none');
        }
        if (contentWrapper) contentWrapper.classList.add('d-none');
        batchDetailsIsLoading = false;
        console.error('Error loading batch details:', error);
    });
}

function renderBatchDetails(data) {
    const summarySection = document.getElementById('batchSummarySection');
    const materialsSection = document.getElementById('batchMaterialsSection');
    const rawMaterialsSection = document.getElementById('batchRawMaterialsSection');
    const workersSection = document.getElementById('batchWorkersSection');

    const batchNumber = data.batch_number ?? '—';
    const summaryRows = [
        ['رقم التشغيلة', batchNumber],
        ['المنتج', data.product_name ?? '—'],
        ['تاريخ الإنتاج', data.production_date ? data.production_date : '—'],
        ['الكمية المنتجة', data.quantity_produced ?? data.quantity ?? '—']
    ];

    if (data.honey_supplier_name) {
        summaryRows.push(['مورد العسل', data.honey_supplier_name]);
    }
    if (data.packaging_supplier_name) {
        summaryRows.push(['مورد التعبئة', data.packaging_supplier_name]);
    }
    if (data.notes) {
        summaryRows.push(['ملاحظات', data.notes]);
    }

    summarySection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>ملخص التشغيلة</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                        <tbody>
                            ${summaryRows.map(([label, value]) => `
                                <tr>
                                    <th class="w-25">${label}</th>
                                    <td>${value}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    const packagingItems = Array.isArray(data.packaging_materials) 
        ? data.packaging_materials 
        : (Array.isArray(data.materials) ? data.materials : []);
    materialsSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-box-seam me-2"></i>مواد التعبئة</h6>
            </div>
            <div class="card-body">
                ${packagingItems.length > 0 ? `
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${packagingItems.map(item => {
                                    const materialName = item.name ?? item.material_name ?? '—';
                                    let quantityDisplay = '—';
                                    
                                    if (item.details) {
                                        // إذا كان الحقل details موجود (من materials)
                                        quantityDisplay = item.details;
                                    } else {
                                        // إذا كانت البيانات منفصلة (من packaging_materials)
                                        const quantity = item.quantity_used ?? item.quantity ?? null;
                                        const unit = item.unit ?? '';
                                        quantityDisplay = quantity !== null && quantity !== undefined 
                                            ? `${quantity} ${unit}`.trim() 
                                            : '—';
                                    }
                                    
                                    return `
                                    <tr>
                                        <td>${materialName}</td>
                                        <td>${quantityDisplay}</td>
                                    </tr>
                                `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا توجد مواد تعبئة مسجلة
                    </div>
                `}
            </div>
        </div>
    `;

    const rawMaterialsItems = Array.isArray(data.raw_materials) ? data.raw_materials : [];
    rawMaterialsSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-flower1 me-2"></i>الخامات</h6>
            </div>
            <div class="card-body">
                ${rawMaterialsItems.length > 0 ? `
                    <div class="table-responsive dashboard-table-wrapper">
                        <table class="table dashboard-table dashboard-table--compact align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>المادة</th>
                                    <th>الكمية</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rawMaterialsItems.map(item => {
                                    const materialName = item.name ?? item.material_name ?? '—';
                                    const quantity = item.quantity_used ?? item.quantity ?? null;
                                    const unit = item.unit ?? '';
                                    const quantityDisplay = quantity !== null && quantity !== undefined 
                                        ? `${quantity} ${unit}`.trim() 
                                        : '—';
                                    return `
                                    <tr>
                                        <td>${materialName}</td>
                                        <td>${quantityDisplay}</td>
                                    </tr>
                                `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا توجد خامات مسجلة
                    </div>
                `}
            </div>
        </div>
    `;

    const workers = Array.isArray(data.workers) ? data.workers : [];
    workersSection.innerHTML = `
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>العمال</h6>
            </div>
            <div class="card-body">
                ${workers.length > 0 ? `
                    <ul class="list-unstyled mb-0">
                        ${workers.map(worker => {
                            const workerName = worker.full_name ?? worker.name ?? '—';
                            return `
                            <li class="mb-2">
                                <i class="bi bi-person-circle me-2 text-primary"></i>
                                ${workerName}
                            </li>
                        `;
                        }).join('')}
                    </ul>
                ` : `
                    <div class="text-center text-muted py-3">
                        <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                        لا يوجد عمال مسجلون
                    </div>
                `}
            </div>
        </div>
    `;
}

// دالة لمسح cache يدوياً
function clearBatchDetailsCache(batchNumber = null) {
    if (batchNumber) {
        // مسح تشغيلة محددة
        batchDetailsCache.delete(batchNumber);
    } else {
        // مسح جميع البيانات من cache
        batchDetailsCache.clear();
    }
}

// تنظيف cache تلقائياً كل 5 دقائق
setInterval(() => {
    cleanBatchDetailsCache();
}, 5 * 60 * 1000); // 5 دقائق

// تنظيف cache عند تحميل الصفحة
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', cleanBatchDetailsCache);
} else {
    cleanBatchDetailsCache();
}

// جعل الدوال متاحة عالمياً
window.showBatchDetailsModal = showBatchDetailsModal;
window.showBarcodePrintModal = showBarcodePrintModal;
window.printBarcodes = printBarcodes;
window.clearBatchDetailsCache = clearBatchDetailsCache;

// ربط الأحداث للأزرار - انتظار تحميل DOM بالكامل
function initBatchDetailsEventListeners() {
    // انتظار تحميل الصفحة بالكامل (CSS + JS) قبل ربط الأحداث
    function waitForResources() {
        if (document.readyState === 'complete') {
            setTimeout(attachBatchDetailsListeners, 200);
        } else {
            window.addEventListener('load', function() {
                setTimeout(attachBatchDetailsListeners, 200);
            });
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitForResources);
    } else {
        waitForResources();
    }
    
    // إضافة event listener للنموذج عند فتحه لضمان جاهزية العناصر
    const modalElement = document.getElementById('batchDetailsModal');
    if (modalElement) {
        modalElement.addEventListener('shown.bs.modal', function() {
            // استخدام requestAnimationFrame لضمان أن النموذج أصبح مرئياً بالكامل في DOM
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    // التأكد من وجود العناصر
                    const loader = modalElement.querySelector('#batchDetailsLoading');
                    const errorAlert = modalElement.querySelector('#batchDetailsError');
                    const contentWrapper = modalElement.querySelector('#batchDetailsContent');
                    
                    // إنشاء errorAlert ديناميكياً إذا لم يكن موجوداً
                    if (!errorAlert) {
                        const modalBody = modalElement.querySelector('.modal-body');
                        if (modalBody) {
                            const errorDivElement = document.createElement('div');
                            errorDivElement.className = 'alert alert-danger d-none';
                            errorDivElement.id = 'batchDetailsError';
                            errorDivElement.setAttribute('role', 'alert');
                            // إدراجه بعد loader
                            if (loader && loader.nextSibling) {
                                modalBody.insertBefore(errorDivElement, loader.nextSibling);
                            } else if (loader) {
                                modalBody.insertBefore(errorDivElement, loader);
                            } else {
                                modalBody.appendChild(errorDivElement);
                            }
                        }
                    }
                });
            });
        });
    }
}

// تهيئة الأحداث
initBatchDetailsEventListeners();

let batchDetailsListenerAttached = false;

function attachBatchDetailsListeners() {
    // التأكد من إضافة المستمع مرة واحدة فقط
    if (batchDetailsListenerAttached) {
        return;
    }
    batchDetailsListenerAttached = true;
    
    document.addEventListener('click', function(event) {
        // زر تفاصيل التشغيلة
        const detailsButton = event.target.closest('.js-batch-details');
        if (detailsButton) {
            event.preventDefault();
            event.stopPropagation();
            const batchNumber = detailsButton.getAttribute('data-batch') || detailsButton.dataset.batch;
            const productName = detailsButton.getAttribute('data-product') || detailsButton.dataset.product || '';
            if (batchNumber && batchNumber.trim() !== '') {
                if (typeof showBatchDetailsModal === 'function') {
                    showBatchDetailsModal(batchNumber, productName);
                } else {
                    console.error('showBatchDetailsModal function not found');
                }
            }
            return;
        }

        // زر طباعة الباركود
        const printButton = event.target.closest('.js-print-barcode');
        if (printButton) {
            event.preventDefault();
            event.stopPropagation();
            const batchNumber = printButton.getAttribute('data-batch') || printButton.dataset.batch;
            const productName = printButton.getAttribute('data-product') || printButton.dataset.product || '';
            const quantity = parseFloat(printButton.getAttribute('data-quantity') || printButton.dataset.quantity || '1');
            if (batchNumber && batchNumber.trim() !== '') {
                if (typeof window.showBarcodePrintModal === 'function') {
                    window.showBarcodePrintModal(batchNumber, productName, quantity);
                } else {
                    console.error('showBarcodePrintModal function not found');
                }
            }
            return;
        }
    });
}

// معالجة تعديل المنتجات الخارجية
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        initEditExternalButtons();
    });
} else {
    initEditExternalButtons();
}

function initEditExternalButtons() {
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
            
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                new bootstrap.Modal(document.getElementById('editExternalProductModal')).show();
            } else {
                console.error('Bootstrap Modal not available');
            }
        });
    });

    // معالجة حذف المنتجات الخارجية
    document.querySelectorAll('.js-delete-external').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;
            const name = this.dataset.name;
            
            document.getElementById('delete_product_id').value = id;
            document.getElementById('delete_product_name').textContent = name;
            
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                new bootstrap.Modal(document.getElementById('deleteExternalProductModal')).show();
            } else {
                console.error('Bootstrap Modal not available');
            }
        });
    });
}
</script>

