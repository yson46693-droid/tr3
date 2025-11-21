<?php
/**
 * صفحة نقطة البيع المحلية (POS)
 * للمدير والمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

// الحصول على مخزن الشركة الرئيسي
$mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
if (!$mainWarehouse) {
    // إنشاء مخزن رئيسي افتراضي إذا لم يكن موجوداً
    $db->execute(
        "INSERT INTO warehouses (name, warehouse_type, status) VALUES (?, 'main', 'active')",
        ['مخزن الشركة الرئيسي']
    );
    $mainWarehouse = $db->queryOne("SELECT id, name FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1");
}

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_customer') {
        // إضافة عميل جديد
        $customerName = trim($_POST['name'] ?? '');
        $customerPhone = trim($_POST['phone'] ?? '');
        $customerEmail = trim($_POST['email'] ?? '');
        $customerAddress = trim($_POST['address'] ?? '');
        
        if (empty($customerName)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            // التحقق من عدم تكرار رقم الهاتف
            if (!empty($customerPhone)) {
                $existing = $db->queryOne("SELECT id FROM customers WHERE phone = ?", [$customerPhone]);
                if ($existing) {
                    $error = 'رقم الهاتف موجود بالفعل';
                }
            }
            
            if (empty($error)) {
                $db->execute(
                    "INSERT INTO customers (name, phone, email, address, status, created_by) 
                     VALUES (?, ?, ?, ?, 'active', ?)",
                    [$customerName, $customerPhone ?: null, $customerEmail ?: null, $customerAddress ?: null, $currentUser['id']]
                );
                
                // تطبيق PRG pattern لمنع التكرار
                $currentPage = $_SERVER['PHP_SELF'] ?? '';
                $isManagerPage = (strpos($currentPage, 'manager.php') !== false);
                $redirectUrl = getRelativeUrl($isManagerPage ? 'dashboard/manager.php' : 'dashboard/accountant.php');
                $redirectUrl .= '?page=' . ($isManagerPage ? 'pos&section=local' : 'pos');
                
                preventDuplicateSubmission('تم إضافة العميل بنجاح', [], $redirectUrl);
            }
        }
    } elseif ($action === 'complete_sale') {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $itemsPayload = $_POST['items'] ?? '[]';
        $rawItems = json_decode($itemsPayload, true);
        $discountInput = cleanFinancialValue($_POST['discount_amount'] ?? 0);
        $paymentType = $_POST['payment_type'] ?? 'full';
        $partialAmountInput = cleanFinancialValue($_POST['partial_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        $validationErrors = [];

        if ($customerId <= 0) {
            $validationErrors[] = 'يجب اختيار العميل.';
        }

        if (!is_array($rawItems) || empty($rawItems)) {
            $validationErrors[] = 'يجب إضافة منتجات للبيع.';
        }

        if (empty($validationErrors)) {
            try {
                $conn = $db->getConnection();
                $conn->begin_transaction();

                $normalizedCart = [];
                $subtotal = 0.0;

                foreach ($rawItems as $index => $itemRow) {
                    $productId = isset($itemRow['product_id']) ? (int)$itemRow['product_id'] : 0;
                    $quantityRequested = cleanFinancialValue($itemRow['quantity'] ?? 0);
                    $postedUnitPrice = cleanFinancialValue($itemRow['unit_price'] ?? 0);

                    if ($productId <= 0 || $quantityRequested <= 0) {
                        throw new RuntimeException('المنتج أو الكمية غير صالحة في الصف #' . ($index + 1) . '.');
                    }

                    $productRow = $db->queryOne(
                        "SELECT id, name, quantity, unit_price, category FROM products WHERE id = ? FOR UPDATE",
                        [$productId]
                    );

                    if (!$productRow) {
                        throw new RuntimeException('المنتج المحدد غير موجود (معرف: ' . $productId . ').');
                    }

                    $availableQuantity = (float)($productRow['quantity'] ?? 0);
                    if ($quantityRequested > $availableQuantity) {
                        throw new RuntimeException('الكمية المتاحة للمنتج ' . $productRow['name'] . ' غير كافية. المتاح حالياً: ' . $availableQuantity);
                    }

                    $unitPrice = $postedUnitPrice > 0 ? $postedUnitPrice : (float)($productRow['unit_price'] ?? 0);
                    if ($unitPrice <= 0) {
                        throw new RuntimeException('لا يمكن تحديد سعر المنتج ' . $productRow['name'] . '.');
                    }

                    $lineTotal = round($unitPrice * $quantityRequested, 2);
                    $subtotal += $lineTotal;

                    $normalizedCart[] = [
                        'product_id' => $productId,
                        'name' => $productRow['name'] ?? 'منتج',
                        'category' => $productRow['category'] ?? null,
                        'quantity' => $quantityRequested,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ];
                }

                if ($subtotal <= 0) {
                    throw new RuntimeException('لا يمكن إتمام عملية بيع بمجموع صفري.');
                }

                $discountAmount = min(max($discountInput, 0), $subtotal);
                $netTotal = round(max(0, $subtotal - $discountAmount), 2);

                if ($netTotal <= 0) {
                    throw new RuntimeException('القيمة النهائية بعد الخصم يجب أن تكون أكبر من صفر.');
                }

                if (!in_array($paymentType, ['full', 'partial', 'credit'], true)) {
                    $paymentType = 'full';
                }

                $effectivePaidAmount = 0.0;
                if ($paymentType === 'full') {
                    $effectivePaidAmount = $netTotal;
                    $partialAmountInput = 0;
                } elseif ($paymentType === 'partial') {
                    if ($partialAmountInput <= 0) {
                        throw new RuntimeException('يجب إدخال مبلغ التحصيل الجزئي.');
                    }
                    if ($partialAmountInput >= $netTotal) {
                        throw new RuntimeException('مبلغ التحصيل الجزئي يجب أن يكون أقل من الإجمالي بعد الخصم.');
                    }
                    $effectivePaidAmount = $partialAmountInput;
                } else {
                    $partialAmountInput = 0;
                    $effectivePaidAmount = 0.0;
                }

                $baseDueAmount = round(max(0, $netTotal - $effectivePaidAmount), 2);
                $dueAmount = $baseDueAmount;
                $creditUsed = 0.0;

                $customerRow = $db->queryOne(
                    "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                    [$customerId]
                );

                if (!$customerRow) {
                    throw new RuntimeException('تعذر تحميل بيانات العميل أثناء المعالجة.');
                }

                $currentBalance = (float)($customerRow['balance'] ?? 0);
                if ($currentBalance < 0 && $dueAmount > 0) {
                    // إذا كان للعميل رصيد دائن (سالب)، يتم استخدامه لخفض الدين
                    $creditUsed = min(abs($currentBalance), $dueAmount);
                    $dueAmount = round($dueAmount - $creditUsed, 2);
                    // إضافة الرصيد المستخدم إلى المبلغ المدفوع
                    $effectivePaidAmount += $creditUsed;
                }

                // حساب الرصيد الجديد: الرصيد الحالي + الرصيد المستخدم + الدين المتبقي
                $newBalance = round($currentBalance + $creditUsed + $dueAmount, 2);
                if (abs($newBalance - $currentBalance) > 0.0001) {
                    $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                }

                $invoiceItems = [];
                foreach ($normalizedCart as $item) {
                    $invoiceItems[] = [
                        'product_id' => $item['product_id'],
                        'description' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ];
                }

                $invoiceResult = createInvoice(
                    $customerId,
                    $currentUser['id'],
                    date('Y-m-d'),
                    $invoiceItems,
                    0,
                    $discountAmount,
                    $notes,
                    $currentUser['id']
                );

                if (empty($invoiceResult['success'])) {
                    throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة.');
                }

                $invoiceId = (int)$invoiceResult['invoice_id'];
                $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                // تحديد حالة الفاتورة
                // إذا كان الدين = 0 (إما دفعة كاملة نقداً أو استخدام الرصيد الدائن بالكامل)، تكون الفاتورة مدفوعة
                $invoiceStatus = 'sent';
                if ($dueAmount <= 0.0001) {
                    $invoiceStatus = 'paid';
                    // عند استخدام الرصيد الدائن بالكامل، المبلغ المدفوع = إجمالي الفاتورة
                    if ($creditUsed > 0 && $effectivePaidAmount < $netTotal) {
                        $effectivePaidAmount = $netTotal;
                    }
                } elseif ($effectivePaidAmount > 0) {
                    $invoiceStatus = 'partial';
                }

                // تحديث الفاتورة بالمبلغ المدفوع والمبلغ المتبقي
                $db->execute(
                    "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$effectivePaidAmount, $dueAmount, $invoiceStatus, $invoiceId]
                );

                foreach ($normalizedCart as $item) {
                    $movement = recordInventoryMovement(
                        $item['product_id'],
                        $mainWarehouse['id'],
                        'out',
                        $item['quantity'],
                        'invoice',
                        $invoiceId,
                        'بيع مباشر من نقطة البيع',
                        $currentUser['id']
                    );

                    if (empty($movement['success'])) {
                        throw new RuntimeException($movement['message'] ?? 'تعذر تحديث المخزون.');
                    }
                }

                logAudit($currentUser['id'], 'pos_local_sale', 'invoice', $invoiceId, null, [
                    'invoice_number' => $invoiceNumber,
                    'net_total' => $netTotal,
                    'paid_amount' => $effectivePaidAmount,
                    'due_amount' => $dueAmount,
                    'discount' => $discountAmount,
                    'payment_type' => $paymentType,
                    'credit_used' => $creditUsed,
                ]);

                $conn->commit();

                $successMessage = 'تم إتمام عملية البيع بنجاح - رقم الفاتورة: ' . $invoiceNumber;
                if ($dueAmount > 0) {
                    $successMessage .= ' (المتبقي على العميل: ' . formatCurrency($dueAmount) . ')';
                }

                // تطبيق PRG pattern لمنع التكرار
                $currentPage = $_SERVER['PHP_SELF'] ?? '';
                $isManagerPage = (strpos($currentPage, 'manager.php') !== false);
                $redirectUrl = getRelativeUrl($isManagerPage ? 'dashboard/manager.php' : 'dashboard/accountant.php');
                $redirectUrl .= '?page=' . ($isManagerPage ? 'pos&section=local' : 'pos');
                
                preventDuplicateSubmission($successMessage, [], $redirectUrl);

                // إعادة تحميل المنتجات والإحصائيات بعد البيع
                [$products, $productStats, $productsMap] = $loadLocalPosProducts();
                $totalProductsCount = $productStats['total_products'];
                $totalQuantity = $productStats['total_quantity'];
                $totalStockValue = $productStats['total_value'];
                $uniqueCategories = $productStats['categories'];
                $totalCategories = count($uniqueCategories);
            } catch (Throwable $exception) {
                if (isset($conn) && $conn) {
                    $conn->rollback();
                }
                // لا نطبق redirect عند وجود خطأ، فقط نعرض الرسالة
                $error = 'حدث خطأ أثناء إتمام عملية البيع: ' . $exception->getMessage();
            }
        } else {
            // لا نطبق redirect عند وجود أخطاء في التحقق، فقط نعرض الرسائل
            $error = implode('<br>', array_map('htmlspecialchars', $validationErrors));
        }
    }
}

// الحصول على المنتجات من المخزن الرئيسي
$unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
$warehouseColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'warehouse_id'");
$productTypeColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");

$hasUnitColumn = !empty($unitColumnCheck);
$hasWarehouseColumn = !empty($warehouseColumnCheck);
$hasProductTypeColumn = !empty($productTypeColumnCheck);

$loadLocalPosProducts = static function () use ($db, $mainWarehouse, $hasUnitColumn, $hasWarehouseColumn, $hasProductTypeColumn) {
    $fields = ['id', 'name', 'quantity', 'unit_price', 'category'];
    if ($hasUnitColumn) {
        $fields[] = 'unit';
    }

    $sql = "SELECT " . implode(', ', $fields) . " FROM products WHERE status = 'active' AND quantity > 0";
    $params = [];

    if ($hasProductTypeColumn) {
        $sql .= " AND (product_type IS NULL OR product_type = 'internal')";
    }

    if ($hasWarehouseColumn && !empty($mainWarehouse['id'])) {
        $sql .= " AND (warehouse_id = ? OR warehouse_id IS NULL)";
        $params[] = $mainWarehouse['id'];
    }

    $sql .= " ORDER BY name";

    $list = $db->query($sql, $params);

    if (!$hasUnitColumn) {
        foreach ($list as &$productRow) {
            if (!isset($productRow['unit'])) {
                $productRow['unit'] = 'قطعة';
            }
        }
        unset($productRow);
    }

    $stats = [
        'total_products' => 0,
        'total_quantity' => 0.0,
        'total_value' => 0.0,
        'categories' => [],
    ];

    $map = [];
    foreach ($list as $row) {
        $stats['total_products']++;
        $stats['total_quantity'] += (float)($row['quantity'] ?? 0);
        $stats['total_value'] += (float)($row['quantity'] ?? 0) * (float)($row['unit_price'] ?? 0);
        $label = trim((string)($row['category'] ?? ''));
        if ($label !== '') {
            $stats['categories'][$label] = true;
        }
        $map[(int)$row['id']] = $row;
    }

    return [$list, $stats, $map];
};

[$products, $productStats, $productsMap] = $loadLocalPosProducts();

// الحصول على العملاء
$customers = $db->query("SELECT id, name, phone FROM customers WHERE status = 'active' ORDER BY name");

$totalProductsCount = $productStats['total_products'];
$totalQuantity = $productStats['total_quantity'];
$totalStockValue = $productStats['total_value'];
$uniqueCategories = $productStats['categories'];
$totalCategories = count($uniqueCategories);
?>
<!-- Local POS Header Section -->
<div class="local-pos-header mb-4">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-3 mb-lg-0">
                    <h2 class="mb-2 fw-bold text-primary">
                        <i class="bi bi-cash-register me-2"></i>نقطة البيع المحلية
                    </h2>
                    <p class="text-muted mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        بيع مباشر من مخزن الشركة الرئيسي وإدارة الفواتير الفورية
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="d-flex flex-column flex-sm-row gap-3 justify-content-lg-end">
                        <div class="card bg-light border-0 flex-grow-1 flex-sm-grow-0" style="min-width: 250px;">
                            <div class="card-body p-3 d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <small class="text-muted d-block mb-1">
                                        <i class="bi bi-building me-1"></i>المخزن الحالي
                                    </small>
                                    <strong class="text-dark"><?php echo htmlspecialchars($mainWarehouse['name']); ?></strong>
                                </div>
                                <i class="bi bi-building-check text-primary fs-4"></i>
                            </div>
                        </div>
                        <button class="btn btn-primary btn-lg shadow-sm" type="button" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                            <i class="bi bi-person-plus me-2"></i>إضافة عميل جديد
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
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

<div class="pos-wrapper">
    <section class="pos-warehouse-summary">
        <div class="pos-summary-card">
            <span class="label">المخزن الرئيسي</span>
            <div class="value"><?php echo htmlspecialchars($mainWarehouse['name']); ?></div>
            <div class="meta">هذا المخزن مخصص لعمليات نقطة البيع المحلية.</div>
            <i class="bi bi-building icon"></i>
        </div>
        <div class="pos-summary-card">
            <span class="label">منتجات جاهزة</span>
            <div class="value"><?php echo number_format($totalProductsCount); ?></div>
            <div class="meta">أصناف متاحة للبيع من المخزن.</div>
            <i class="bi bi-box-seam icon"></i>
        </div>
        <div class="pos-summary-card">
            <span class="label">إجمالي الكميات</span>
            <div class="value"><?php echo number_format($totalQuantity, 2); ?></div>
            <div class="meta">إجمالي الوحدات الحالية في المخزون.</div>
            <i class="bi bi-stack icon"></i>
        </div>
    </section>

    <section class="pos-content">
        <div class="pos-panel pos-inventory-panel" style="grid-column: span 7;">
            <div class="pos-panel-header">
                <div>
                    <h4 class="mb-1"><i class="bi bi-box-seam me-1"></i>المنتجات المتاحة</h4>
                    <p class="mb-0 text-muted small">استعرض المنتجات المتاحة في مخزن الشركة وأضفها للسلة بنقرة واحدة.</p>
                </div>
                <div class="pos-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="posInventorySearch" class="form-control" placeholder="بحث سريع عن منتج..." <?php echo empty($products) ? 'disabled' : ''; ?>>
                </div>
            </div>
            <div class="pos-product-grid" id="posProductGrid">
                <?php if (empty($products)): ?>
                    <div class="pos-empty pos-empty-inline">
                        <i class="bi bi-box"></i>
                        <p class="mb-0">لا توجد منتجات متاحة في المخزن حالياً.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <div class="pos-product-card"
                             data-product-card
                             data-product-id="<?php echo (int) $product['id']; ?>"
                             data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-category="<?php echo htmlspecialchars($product['category'] ?? 'غير مصنف', ENT_QUOTES, 'UTF-8'); ?>"
                             data-price="<?php echo (float) $product['unit_price']; ?>"
                             data-stock="<?php echo (float) $product['quantity']; ?>"
                             data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="pos-product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                            <div class="pos-product-meta">
                                <span class="pos-product-badge"><?php echo htmlspecialchars($product['category'] ?? 'عام'); ?></span>
                                <span class="pos-product-qty">المتوفر: <?php echo number_format((float) $product['quantity'], 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></span>
                            </div>
                            <div class="pos-product-meta">
                                <span class="text-primary fw-semibold"><?php echo formatCurrency($product['unit_price']); ?></span>
                            </div>
                            <button type="button" class="btn btn-outline-primary pos-select-btn" data-add-to-cart>
                                <i class="bi bi-cart-plus me-1"></i>إضافة للسلة
                            </button>
                        </div>
                    <?php endforeach; ?>
                    <div class="pos-empty pos-empty-inline d-none" id="posNoResults">
                        <i class="bi bi-search"></i>
                        <p class="mb-0">لم يتم العثور على منتجات مطابقة لبحثك.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="pos-panel pos-checkout-panel" style="grid-column: span 5;">
            <div class="pos-panel-header">
                <div>
                    <h4 class="mb-1"><i class="bi bi-cart-check me-1"></i>سلة المبيعات</h4>
                    <p class="mb-0 text-muted small">راجع العناصر، حدّث الكميات وأكمل عملية البيع فوراً.</p>
                </div>
                <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="bi bi-person-plus me-1"></i>عميل جديد
                </button>
            </div>
            <form id="posForm" method="POST" class="pos-form needs-validation" novalidate>
                <input type="hidden" name="action" value="complete_sale">
                <input type="hidden" name="items" id="posCartData">

                <div class="mb-3">
                    <label class="form-label">العميل <span class="text-danger">*</span></label>
                    <select class="form-select" name="customer_id" id="posCustomerSelect" required>
                        <option value="">اختر العميل</option>
                        <?php foreach ($customers as $customer): ?>
                            <option value="<?php echo (int) $customer['id']; ?>">
                                <?php echo htmlspecialchars($customer['name']); ?>
                                <?php if (!empty($customer['phone'])): ?>
                                    - <?php echo htmlspecialchars($customer['phone']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">يمكنك إضافة عميل جديد من الزر أعلاه.</small>
                </div>

                <div class="pos-cart-empty" id="posCartEmpty">
                    <i class="bi bi-cart-x"></i>
                    <p class="mb-0">السلة فارغة حالياً، ابدأ بإضافة المنتجات.</p>
                </div>

                <div class="table-responsive d-none" id="posCartTableWrapper">
                    <table class="table pos-cart-table align-middle">
                        <thead>
                            <tr>
                                <th>المنتج</th>
                                <th>السعر</th>
                                <th>الكمية</th>
                                <th>الإجمالي</th>
                                <th>إجراءات</th>
                            </tr>
                        </thead>
                        <tbody id="posCartBody"></tbody>
                    </table>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label small">الخصم (ج.م)</label>
                        <input type="number" step="0.01" min="0" value="0" class="form-control" name="discount_amount" id="posDiscountInput">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">ملاحظات</label>
                        <textarea class="form-control" name="notes" rows="1" placeholder="ملاحظات اختيارية"></textarea>
                    </div>
                </div>

                <div class="pos-summary-card-neutral mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>المجموع الفرعي</span>
                        <span class="total" id="posCartSubtotal"><?php echo formatCurrency(0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span>الإجمالي بعد الخصم</span>
                        <span class="total" id="posNetTotal"><?php echo formatCurrency(0); ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span>المتبقي على العميل</span>
                        <span class="total text-warning" id="posDueAmount"><?php echo formatCurrency(0); ?></span>
                    </div>
                </div>

                <div class="mb-3 mt-3">
                    <label class="form-label">طريقة الدفع</label>
                    <div class="pos-payment-options">
                        <label class="pos-payment-option active" for="posPaymentFull" data-payment-option>
                            <input class="form-check-input" type="radio" name="payment_type" id="posPaymentFull" value="full" checked>
                            <div class="pos-payment-option-icon">
                                <i class="bi bi-cash-stack"></i>
                            </div>
                            <div class="pos-payment-option-details">
                                <span class="pos-payment-option-title">دفع كامل الآن</span>
                                <span class="pos-payment-option-desc">تحصيل المبلغ بالكامل دون أي ديون.</span>
                            </div>
                        </label>
                        <label class="pos-payment-option" for="posPaymentPartial" data-payment-option>
                            <input class="form-check-input" type="radio" name="payment_type" id="posPaymentPartial" value="partial">
                            <div class="pos-payment-option-icon">
                                <i class="bi bi-cash-coin"></i>
                            </div>
                            <div class="pos-payment-option-details">
                                <span class="pos-payment-option-title">تحصيل جزئي</span>
                                <span class="pos-payment-option-desc">استلام جزء من المبلغ وتسجيل الباقي كدين.</span>
                            </div>
                        </label>
                        <label class="pos-payment-option" for="posPaymentCredit" data-payment-option>
                            <input class="form-check-input" type="radio" name="payment_type" id="posPaymentCredit" value="credit">
                            <div class="pos-payment-option-icon">
                                <i class="bi bi-receipt"></i>
                            </div>
                            <div class="pos-payment-option-details">
                                <span class="pos-payment-option-title">بيع بالآجل</span>
                                <span class="pos-payment-option-desc">تسجيل المبلغ كدين كامل للمتابعة لاحقاً.</span>
                            </div>
                        </label>
                    </div>
                    <div class="mt-3 d-none" id="posPartialWrapper">
                        <label class="form-label">مبلغ التحصيل الجزئي</label>
                        <input type="number" step="0.01" min="0" value="0" class="form-control" id="posPartialAmount" name="partial_amount">
                    </div>
                </div>

                <div class="d-grid gap-2 mt-3">
                    <button type="button" class="btn btn-outline-light" id="posClearCartBtn">
                        <i class="bi bi-trash me-1"></i>مسح السلة
                    </button>
                    <button type="submit" class="btn btn-success btn-lg" id="posCompleteSaleBtn" disabled>
                        <i class="bi bi-check-circle me-1"></i>إتمام عملية البيع
                    </button>
                </div>
            </form>
        </div>
    </section>
</div>

<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>إضافة عميل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_customer">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" class="form-control" name="phone" placeholder="مثال: 01234567890">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ديون العميل</label>
                        <input type="number" class="form-control" name="balance" value="0" min="0" step="0.01">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
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

<script>
let cart = [];

const productSearchInput = document.getElementById('posInventorySearch');
const productCards = Array.from(document.querySelectorAll('[data-product-card]'));
const noResultsState = document.getElementById('posNoResults');

const cartDataInput = document.getElementById('posCartData');
const cartEmptyState = document.getElementById('posCartEmpty');
const cartTableWrapper = document.getElementById('posCartTableWrapper');
const cartBody = document.getElementById('posCartBody');
const subtotalEl = document.getElementById('posCartSubtotal');
const totalEl = document.getElementById('posNetTotal');
const dueEl = document.getElementById('posDueAmount');
const discountInput = document.getElementById('posDiscountInput');
const completeSaleBtn = document.getElementById('posCompleteSaleBtn');
const clearCartBtn = document.getElementById('posClearCartBtn');
const paymentRadios = Array.from(document.querySelectorAll('input[name="payment_type"]'));
const paymentOptionLabels = Array.from(document.querySelectorAll('[data-payment-option]'));
const partialWrapper = document.getElementById('posPartialWrapper');
const partialInput = document.getElementById('posPartialAmount');

function filterProducts() {
    if (!productSearchInput) {
        return;
    }

    const term = productSearchInput.value.trim().toLowerCase();
    let visibleCount = 0;

    productCards.forEach((card) => {
        const name = (card.dataset.name || '').toLowerCase();
        const category = (card.dataset.category || '').toLowerCase();
        const matches = term === '' || name.includes(term) || category.includes(term);

        card.style.display = matches ? '' : 'none';
        if (matches) {
            visibleCount++;
        }
    });

    if (noResultsState) {
        noResultsState.classList.toggle('d-none', visibleCount !== 0);
    }
}

if (productSearchInput) {
    productSearchInput.addEventListener('input', filterProducts);
}

productCards.forEach((card) => {
    const trigger = card.querySelector('[data-add-to-cart]') || card;
    trigger.addEventListener('click', () => handleAddToCart(card));
});

function handleAddToCart(card) {
    const productId = parseInt(card.dataset.productId, 10);
    if (!productId) {
        return;
    }

    const available = parseFloat(card.dataset.stock || '0');
    const existingIndex = cart.findIndex((item) => item.product_id === productId);

    if (existingIndex >= 0) {
        if (cart[existingIndex].quantity + 1 > available) {
            alert('الكمية المتاحة غير كافية لهذا المنتج.');
            return;
        }
        cart[existingIndex].quantity += 1;
    } else {
        cart.push({
            product_id: productId,
            name: card.dataset.name || 'منتج',
            unit_price: parseFloat(card.dataset.price || '0'),
            quantity: 1,
            available_quantity: available,
            unit: card.dataset.unit || 'وحدة'
        });
    }

    card.classList.add('active');
    setTimeout(() => card.classList.remove('active'), 350);
    updateCartDisplay();
}

function updateCartDisplay() {
    if (!cartBody) {
        return;
    }

    if (cart.length === 0) {
        cartBody.innerHTML = '';
        if (cartDataInput) {
            cartDataInput.value = '[]';
        }
        if (cartEmptyState) {
            cartEmptyState.classList.remove('d-none');
        }
        if (cartTableWrapper) {
            cartTableWrapper.classList.add('d-none');
        }
        if (completeSaleBtn) {
            completeSaleBtn.disabled = true;
        }
        updateTotals(0);
        return;
    }

    let subtotal = 0;
    const rows = cart.map((item, index) => {
        const itemTotal = item.quantity * item.unit_price;
        subtotal += itemTotal;
        const maxQty = Math.max(1, item.available_quantity);

        return `
            <tr>
                <td data-label="المنتج">
                    <div class="fw-semibold">${escapeHtml(item.name)}</div>
                    <div class="text-muted small">${escapeHtml(item.unit)}</div>
                </td>
                <td data-label="السعر">${formatCurrency(item.unit_price)}</td>
                <td data-label="الكمية">
                    <div class="pos-qty-control">
                        <button type="button" class="btn btn-outline-secondary" data-action="decrease" onclick="updateQuantity(${index}, -1)">-</button>
                        <input type="number" class="form-control text-center" value="${item.quantity}" min="1" max="${maxQty}" onchange="setQuantity(${index}, this.value)">
                        <button type="button" class="btn btn-outline-secondary" data-action="increase" onclick="updateQuantity(${index}, 1)">+</button>
                    </div>
                    <div class="text-muted small mt-1">متاح: ${maxQty}</div>
                </td>
                <td data-label="الإجمالي">${formatCurrency(itemTotal)}</td>
                <td data-label="إجراءات">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeFromCart(${index})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    cartBody.innerHTML = rows;
    if (cartDataInput) {
        cartDataInput.value = JSON.stringify(cart);
    }
    if (cartEmptyState) {
        cartEmptyState.classList.add('d-none');
    }
    if (cartTableWrapper) {
        cartTableWrapper.classList.remove('d-none');
    }
    if (completeSaleBtn) {
        completeSaleBtn.disabled = false;
    }
    updateTotals(subtotal);
}

function sanitizeNumber(value) {
    if (typeof value === 'number') {
        return isNaN(value) ? 0 : value;
    }
    if (typeof value === 'string') {
        const normalized = value.replace(/[^0-9.\-]/g, '');
        if (normalized === '' || normalized === '-') {
            return 0;
        }
        return parseFloat(normalized) || 0;
    }
    return 0;
}

function updateTotals(forcedSubtotal) {
    const subtotal = typeof forcedSubtotal === 'number'
        ? forcedSubtotal
        : cart.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);

    const discountValue = sanitizeNumber(discountInput ? discountInput.value : '0');
    const sanitizedDiscount = Math.min(Math.max(discountValue, 0), subtotal);

    if (discountInput && sanitizedDiscount !== discountValue) {
        discountInput.value = sanitizedDiscount.toFixed(2);
    }

    if (subtotalEl) {
        subtotalEl.textContent = formatCurrency(subtotal);
    }

    const netTotal = Math.max(0, subtotal - sanitizedDiscount);
    if (totalEl) {
        totalEl.textContent = formatCurrency(netTotal);
    }

    const paymentType = getSelectedPaymentType();
    let dueAmount = netTotal;

    if (paymentType === 'full') {
        dueAmount = 0;
        if (partialInput) {
            partialInput.value = '0.00';
        }
    } else if (paymentType === 'partial') {
        if (partialInput) {
            let partialValue = sanitizeNumber(partialInput.value);
            if (partialValue < 0) {
                partialValue = 0;
            }
            if (partialValue >= netTotal && netTotal > 0) {
                partialValue = Math.max(0, netTotal - 0.01);
            }
            partialInput.value = partialValue.toFixed(2);
            dueAmount = Math.max(0, netTotal - partialValue);
        }
    } else {
        if (partialInput) {
            partialInput.value = '0.00';
        }
        dueAmount = netTotal;
    }

    if (dueEl) {
        dueEl.textContent = formatCurrency(dueAmount);
    }

    if (completeSaleBtn) {
        let shouldDisable = cart.length === 0 || netTotal <= 0;
        if (paymentType === 'partial') {
            const partialValue = sanitizeNumber(partialInput ? partialInput.value : 0);
            if (partialValue <= 0 || partialValue >= netTotal) {
                shouldDisable = true;
            }
        }
        completeSaleBtn.disabled = shouldDisable;
    }
}

function getSelectedPaymentType() {
    const activeRadio = paymentRadios.find((input) => input.checked);
    return activeRadio ? activeRadio.value : 'full';
}

function handlePaymentChange() {
    const selectedType = getSelectedPaymentType();

    paymentOptionLabels.forEach((label) => {
        const input = label.querySelector('input[type="radio"]');
        label.classList.toggle('active', input && input.checked);
    });

    if (partialWrapper) {
        if (selectedType === 'partial') {
            partialWrapper.classList.remove('d-none');
        } else {
            partialWrapper.classList.add('d-none');
            if (partialInput) {
                partialInput.value = '0.00';
            }
        }
    }

    updateTotals();
}

function updateQuantity(index, change) {
    if (!cart[index]) {
        return;
    }
    const nextQuantity = cart[index].quantity + change;
    if (nextQuantity >= 1 && nextQuantity <= cart[index].available_quantity) {
        cart[index].quantity = nextQuantity;
        updateCartDisplay();
    }
}

function setQuantity(index, value) {
    if (!cart[index]) {
        return;
    }
    const parsed = parseInt(value, 10) || 1;
    if (parsed >= 1 && parsed <= cart[index].available_quantity) {
        cart[index].quantity = parsed;
        updateCartDisplay();
    } else {
        alert(`الكمية يجب أن تكون بين 1 و ${cart[index].available_quantity}.`);
        updateCartDisplay();
    }
}

function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
}

function clearCart() {
    if (cart.length === 0) {
        return;
    }
    if (confirm('هل أنت متأكد من مسح جميع عناصر السلة؟')) {
        cart = [];
        updateCartDisplay();
    }
}

function escapeHtml(value) {
    return (value || '').toString().replace(/[&<>"']/g, (match) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[match] || match));
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP'
    }).format(amount || 0);
}

if (discountInput) {
    discountInput.addEventListener('input', () => updateTotals());
}

if (partialInput) {
    partialInput.addEventListener('input', () => updateTotals());
}

paymentRadios.forEach((input) => {
    input.addEventListener('change', handlePaymentChange);
});

if (clearCartBtn) {
    clearCartBtn.addEventListener('click', clearCart);
}

filterProducts();
updateCartDisplay();
handlePaymentChange();

<?php if ($success && isset($_POST['action']) && $_POST['action'] === 'create_customer'): ?>
    setTimeout(() => window.location.reload(), 800);
<?php endif; ?>
</script>

<style>
.pos-page-header h2 {
    font-size: 1.75rem;
    font-weight: 700;
    color: #0f172a;
}
.pos-page-header p {
    font-size: 0.95rem;
    color: #475569;
}
.pos-wrapper {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.pos-warehouse-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}
.pos-summary-card {
    position: relative;
    overflow: hidden;
    border-radius: 18px;
    padding: 1.5rem;
    color: #ffffff;
    background: linear-gradient(135deg, rgba(30,58,95,0.95), rgba(44,82,130,0.88));
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.15);
}
.pos-summary-card .label {
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    opacity: 0.8;
}
.pos-summary-card .value {
    font-size: 1.6rem;
    font-weight: 700;
    margin-top: 0.35rem;
}
.pos-summary-card .meta {
    margin-top: 0.35rem;
    font-size: 0.9rem;
    opacity: 0.9;
}
.pos-summary-card .icon {
    position: absolute;
    bottom: 1rem;
    right: 1rem;
    font-size: 2.4rem;
    opacity: 0.12;
}
.pos-content {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 1.5rem;
}
.pos-panel {
    background: #ffffff;
    border-radius: 18px;
    padding: 1.5rem;
    box-shadow: 0 16px 35px rgba(15, 23, 42, 0.12);
    border: 1px solid rgba(15, 23, 42, 0.05);
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.pos-panel-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.5rem;
}
.pos-panel-header h4 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #1f2937;
}
.pos-panel-header p {
    margin: 0;
    color: #64748b;
    font-size: 0.9rem;
}
.pos-search {
    position: relative;
    flex: 1;
    min-width: 220px;
}
.pos-search input {
    padding-inline-start: 2.25rem;
    border-radius: 12px;
    border: 1px solid rgba(99, 102, 241, 0.25);
    height: 44px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.pos-search input:focus {
    border-color: rgba(99, 102, 241, 0.5);
    box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.18);
}
.pos-search i {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    inset-inline-start: 12px;
    color: #64748b;
}
.pos-product-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}
.pos-product-card {
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 16px;
    padding: 1rem;
    background: #f8fafc;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.7);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    cursor: pointer;
}
.pos-product-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 30px rgba(15, 23, 42, 0.12);
    border-color: rgba(59, 130, 246, 0.35);
}
.pos-product-card.active {
    border-color: rgba(22, 163, 74, 0.75);
    box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.2);
}
.pos-product-name {
    font-weight: 600;
    color: #0f172a;
}
.pos-product-meta {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: center;
    font-size: 0.85rem;
    color: #475569;
}
.pos-product-badge {
    background: rgba(59, 130, 246, 0.12);
    color: #2563eb;
    border-radius: 999px;
    padding: 0.25rem 0.65rem;
    font-size: 0.75rem;
}
.pos-product-qty {
    font-size: 0.8rem;
    color: #16a34a;
    font-weight: 600;
}
.pos-select-btn {
    margin-top: auto;
    border-radius: 12px;
}
.pos-checkout-panel {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    color: #f8fafc;
    border: none;
    box-shadow: 0 24px 40px rgba(15, 23, 42, 0.4);
}
.pos-checkout-panel .pos-panel-header h4 {
    color: #f8fafc;
}
.pos-checkout-panel .pos-panel-header p {
    color: rgba(248, 250, 252, 0.7);
}
.pos-form label {
    font-weight: 600;
    color: #cbd5f5;
}
.pos-form .form-select,
.pos-form .form-control {
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: rgba(15, 23, 42, 0.35);
    color: #f8fafc;
}
.pos-form .form-select:focus,
.pos-form .form-control:focus {
    border-color: rgba(99, 102, 241, 0.6);
    box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.25);
    color: #f8fafc;
}
.pos-form .form-control::placeholder {
    color: rgba(226, 232, 240, 0.7);
}
.pos-cart-empty {
    border: 1px dashed rgba(226, 232, 240, 0.4);
    border-radius: 16px;
    padding: 2rem 1rem;
    text-align: center;
    color: rgba(226, 232, 240, 0.75);
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    align-items: center;
}
.pos-cart-empty i {
    font-size: 2.3rem;
    color: rgba(226, 232, 240, 0.65);
}
.pos-cart-table {
    background: #ffffff;
    border-radius: 16px;
    overflow: hidden;
    color: #0f172a;
}
.pos-cart-table thead {
    background: #f1f5f9;
    font-size: 0.85rem;
    color: #475569;
}
.pos-cart-table td {
    vertical-align: middle;
}
.pos-qty-control {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}
.pos-qty-control .btn {
    border-radius: 10px;
    padding: 0.35rem 0.75rem;
}
.pos-qty-control input {
    width: 64px;
    border-radius: 10px;
    text-align: center;
}
.pos-summary-card-neutral {
    background: rgba(248, 250, 252, 0.1);
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 16px;
    padding: 1rem 1.25rem;
    color: #e2e8f0;
}
.pos-summary-card-neutral .total {
    font-weight: 700;
    font-size: 1.2rem;
}
.pos-payment-options {
    display: grid;
    gap: 0.75rem;
}
.pos-payment-option {
    position: relative;
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 0.85rem 1rem;
    border: 1px solid rgba(15, 23, 42, 0.12);
    border-radius: 14px;
    background: #f8fafc;
    transition: all 0.2s ease;
    cursor: pointer;
}
.pos-payment-option:hover {
    border-color: rgba(30, 64, 175, 0.35);
    background: #ffffff;
    box-shadow: 0 12px 26px rgba(15, 23, 42, 0.12);
}
.pos-payment-option.active {
    border-color: rgba(22, 163, 74, 0.7);
    background: #ffffff;
    box-shadow: 0 14px 32px rgba(22, 163, 74, 0.18);
}
.pos-payment-option input[type="radio"] {
    display: none;
}
.pos-payment-option-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: rgba(15, 23, 42, 0.06);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #0f172a;
}
.pos-payment-option.active .pos-payment-option-icon {
    background: rgba(34, 197, 94, 0.18);
    color: #15803d;
}
.pos-payment-option-details {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}
.pos-payment-option-title {
    font-weight: 600;
    color: #0f172a;
}
.pos-payment-option-desc {
    font-size: 0.85rem;
    color: #475569;
}
.pos-panel .btn-success {
    border-radius: 12px;
    box-shadow: 0 10px 20px rgba(34, 197, 94, 0.25);
}
.pos-panel .btn-outline-light,
.pos-panel .btn-outline-light:hover {
    border-radius: 12px;
}
.pos-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 2.5rem 1.25rem;
    border-radius: 18px;
    border: 1px dashed rgba(148, 163, 184, 0.4);
    color: #64748b;
    background: rgba(241, 245, 249, 0.5);
    text-align: center;
}
.pos-empty i {
    font-size: 2.2rem;
    color: #94a3b8;
}
@media (max-width: 1199.98px) {
    .pos-content {
        gap: 1.25rem;
    }
}
@media (max-width: 991.98px) {
    .pos-content {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    .pos-panel {
        padding: 1.25rem;
    }
    .pos-checkout-panel {
        order: -1;
    }
}
@media (max-width: 575.98px) {
    .pos-warehouse-summary {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    .pos-summary-card {
        padding: 1.2rem;
        border-radius: 16px;
    }
    .pos-product-grid {
        grid-template-columns: repeat(1, minmax(0, 1fr));
    }
    .pos-cart-table thead {
        display: none;
    }
    .pos-cart-table tbody tr {
        display: grid;
        gap: 0.75rem;
        padding: 0.75rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.25);
    }
    .pos-cart-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
        border: none !important;
    }
    .pos-cart-table td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #64748b;
    }
}
</style>

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

