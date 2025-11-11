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
                
                $success = 'تم إضافة العميل بنجاح';
            }
        }
    } elseif ($action === 'complete_sale') {
        // إتمام عملية البيع
        $customerId = intval($_POST['customer_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $discountAmount = floatval($_POST['discount_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        if ($customerId <= 0) {
            $error = 'يجب اختيار العميل';
        } elseif (empty($items) || !is_array($items)) {
            $error = 'يجب إضافة منتجات للبيع';
        } else {
            // التحقق من توفر الكميات
            $insufficientProducts = [];
            foreach ($items as $item) {
                $productId = intval($item['product_id'] ?? 0);
                $quantity = floatval($item['quantity'] ?? 0);
                
                if ($productId > 0 && $quantity > 0) {
                    $product = $db->queryOne("SELECT name, quantity FROM products WHERE id = ?", [$productId]);
                    if (!$product) {
                        $insufficientProducts[] = "المنتج غير موجود";
                    } elseif ($product['quantity'] < $quantity) {
                        $insufficientProducts[] = $product['name'] . " (المتاح: " . $product['quantity'] . ")";
                    }
                }
            }
            
            if (!empty($insufficientProducts)) {
                $error = 'الكميات غير كافية للمنتجات التالية: ' . implode(', ', $insufficientProducts);
            } else {
                // إنشاء الفاتورة
                $invoiceItems = [];
                foreach ($items as $item) {
                    $invoiceItems[] = [
                        'product_id' => intval($item['product_id']),
                        'description' => trim($item['description'] ?? ''),
                        'quantity' => floatval($item['quantity']),
                        'unit_price' => floatval($item['unit_price'])
                    ];
                }
                
                $result = createInvoice($customerId, null, date('Y-m-d'), $invoiceItems, 0, $discountAmount, $notes);
                
                if ($result['success']) {
                    // تحديث المخزون
                    foreach ($items as $item) {
                        $productId = intval($item['product_id']);
                        $quantity = floatval($item['quantity']);
                        
                        recordInventoryMovement(
                            $productId,
                            $mainWarehouse['id'],
                            'out',
                            $quantity,
                            'invoice',
                            $result['invoice_id'],
                            'بيع مباشر من نقطة البيع'
                        );
                    }
                    
                    // تحديث حالة الفاتورة إلى مدفوعة (نقداً)
                    $db->execute(
                        "UPDATE invoices SET status = 'paid', paid_amount = total_amount, remaining_amount = 0 WHERE id = ?",
                        [$result['invoice_id']]
                    );
                    
                    logAudit($currentUser['id'], 'pos_sale', 'invoice', $result['invoice_id'], null, [
                        'invoice_number' => $result['invoice_number'],
                        'total_amount' => $result['total_amount'] ?? 0
                    ]);
                    
                    $success = 'تم إتمام عملية البيع بنجاح - رقم الفاتورة: ' . $result['invoice_number'];
                } else {
                    $error = $result['message'] ?? 'حدث خطأ في إنشاء الفاتورة';
                }
            }
        }
    }
}

// الحصول على المنتجات من المخزن الرئيسي
$unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
$hasUnitColumn = !empty($unitColumnCheck);

if ($hasUnitColumn) {
    $products = $db->query(
        "SELECT id, name, quantity, unit_price, unit, category 
         FROM products 
         WHERE status = 'active' AND quantity > 0 
         ORDER BY name"
    );
} else {
    $products = $db->query(
        "SELECT id, name, quantity, unit_price, category 
         FROM products 
         WHERE status = 'active' AND quantity > 0 
         ORDER BY name"
    );
    // إضافة unit افتراضي
    foreach ($products as &$product) {
        $product['unit'] = 'قطعة';
    }
}

// الحصول على العملاء
$customers = $db->query("SELECT id, name, phone FROM customers WHERE status = 'active' ORDER BY name");

$totalProductsCount = is_array($products) ? count($products) : 0;
$totalQuantity = 0.0;
$totalStockValue = 0.0;
$uniqueCategories = [];

if ($totalProductsCount > 0) {
    foreach ($products as $product) {
        $quantity = (float)($product['quantity'] ?? 0);
        $unitPrice = (float)($product['unit_price'] ?? 0);
        $totalQuantity += $quantity;
        $totalStockValue += $quantity * $unitPrice;

        $categoryLabel = trim((string)($product['category'] ?? ''));
        if ($categoryLabel !== '') {
            $uniqueCategories[$categoryLabel] = true;
        }
    }
}

$totalCategories = count($uniqueCategories);
?>
<div class="pos-page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h2 class="mb-1"><i class="bi bi-cash-register me-2"></i>نقطة البيع المحلية</h2>
        <p class="text-muted mb-0">بيع مباشر من مخزن الشركة الرئيسي وإدارة الفواتير الفورية.</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge rounded-pill bg-info text-dark px-3 py-2">
            <i class="bi bi-building-check me-1"></i>المخزن: <?php echo htmlspecialchars($mainWarehouse['name']); ?>
        </span>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="bi bi-person-plus me-2"></i>إضافة عميل جديد
        </button>
    </div>
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

<div class="pos-wrapper">
    <section class="pos-summary">
        <div class="pos-summary-card">
            <div class="icon bg-primary text-white"><i class="bi bi-box-seam"></i></div>
            <div class="label">إجمالي المنتجات</div>
            <div class="value"><?php echo number_format($totalProductsCount); ?></div>
            <div class="meta text-muted">منتج نشط في المخزن</div>
        </div>
        <div class="pos-summary-card">
            <div class="icon bg-success text-white"><i class="bi bi-diagram-3"></i></div>
            <div class="label">الكمية المتاحة</div>
            <div class="value"><?php echo number_format($totalQuantity, 2); ?></div>
            <div class="meta text-muted">إجمالي الوحدات الحالية</div>
        </div>
        <div class="pos-summary-card">
            <div class="icon bg-warning text-dark"><i class="bi bi-currency-exchange"></i></div>
            <div class="label">القيمة التقديرية</div>
            <div class="value"><?php echo formatCurrency($totalStockValue); ?></div>
            <div class="meta text-muted">استناداً إلى أسعار البيع الحالية</div>
        </div>
        <div class="pos-summary-card">
            <div class="icon bg-secondary text-white"><i class="bi bi-people"></i></div>
            <div class="label">عملاء نشطون</div>
            <div class="value"><?php echo number_format(is_array($customers) ? count($customers) : 0); ?></div>
            <div class="meta text-muted">جاهزون لاستلام المبيعات</div>
        </div>
    </section>

    <section class="pos-content">
        <div class="pos-panel" style="grid-column: span 7;">
            <div class="pos-panel-header">
                <div>
                    <h4 class="mb-1"><i class="bi bi-box-seam me-1"></i>المنتجات المتاحة</h4>
                    <p class="mb-0 text-muted small">استعرض المنتجات المتاحة في مخزن الشركة وأضفها للسلة بنقرة واحدة.</p>
                </div>
                <div class="pos-search">
                    <i class="bi bi-search"></i>
                    <input type="text" id="posProductSearch" class="form-control" placeholder="بحث سريع عن منتج..." <?php echo empty($products) ? 'disabled' : ''; ?>>
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
                        <span>الإجمالي المستحق</span>
                        <span class="total text-success" id="posCartTotal"><?php echo formatCurrency(0); ?></span>
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

const productSearchInput = document.getElementById('posProductSearch');
const productCards = Array.from(document.querySelectorAll('[data-product-card]'));
const noResultsState = document.getElementById('posNoResults');

const cartDataInput = document.getElementById('posCartData');
const cartEmptyState = document.getElementById('posCartEmpty');
const cartTableWrapper = document.getElementById('posCartTableWrapper');
const cartBody = document.getElementById('posCartBody');
const subtotalEl = document.getElementById('posCartSubtotal');
const totalEl = document.getElementById('posCartTotal');
const discountInput = document.getElementById('posDiscountInput');
const completeSaleBtn = document.getElementById('posCompleteSaleBtn');
const clearCartBtn = document.getElementById('posClearCartBtn');

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

function updateTotals(forcedSubtotal) {
    const subtotal = typeof forcedSubtotal === 'number'
        ? forcedSubtotal
        : cart.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);

    const discountValue = parseFloat(discountInput ? discountInput.value : '0');
    const sanitizedDiscount = Math.min(Math.max(discountValue, 0), subtotal);

    if (discountInput && sanitizedDiscount !== discountValue) {
        discountInput.value = sanitizedDiscount.toFixed(2);
    }

    if (subtotalEl) {
        subtotalEl.textContent = formatCurrency(subtotal);
    }
    if (totalEl) {
        totalEl.textContent = formatCurrency(Math.max(0, subtotal - sanitizedDiscount));
    }
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

if (clearCartBtn) {
    clearCartBtn.addEventListener('click', clearCart);
}

filterProducts();
updateCartDisplay();

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
.pos-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}
.pos-summary-card {
    position: relative;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 18px;
    padding: 1.25rem;
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
    overflow: hidden;
}
.pos-summary-card .icon {
    position: absolute;
    top: 12px;
    inset-inline-start: 12px;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 1.25rem;
}
.pos-summary-card .label {
    font-size: 0.85rem;
    color: #64748b;
    margin-bottom: 0.35rem;
}
.pos-summary-card .value {
    font-size: 1.85rem;
    font-weight: 700;
    color: #0f172a;
}
.pos-summary-card .meta {
    font-size: 0.8rem;
    color: #94a3b8;
    margin-top: 0.35rem;
}
.pos-content {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 1.25rem;
}
.pos-panel {
    background: #ffffff;
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}
.pos-panel-header {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
}
.pos-panel-header h4 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
}
.pos-panel-header p {
    margin: 0;
    font-size: 0.85rem;
    color: #64748b;
}
.pos-search {
    position: relative;
    width: min(320px, 100%);
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
    box-shadow: 0 0 0 0.2rem rgba(99, 102, 241, 0.15);
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
.pos-cart-empty i { font-size: 2.3rem; color: rgba(226, 232, 240, 0.65); }
.pos-cart-table {
    background: #ffffff;
    border-radius: 16px;
    overflow: hidden;
    color: #0f172a;
}
.pos-cart-table thead { background: #f1f5f9; font-size: 0.85rem; color: #475569; }
.pos-cart-table td { vertical-align: middle; }
.pos-qty-control { display: inline-flex; align-items: center; gap: 0.35rem; }
.pos-qty-control .btn { border-radius: 10px; padding: 0.35rem 0.75rem; }
.pos-qty-control input { width: 64px; border-radius: 10px; text-align: center; }
.pos-summary-card-neutral {
    background: rgba(248, 250, 252, 0.1);
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 16px;
    padding: 1rem 1.25rem;
    color: #e2e8f0;
}
.pos-summary-card-neutral .total { font-weight: 700; font-size: 1.2rem; }
.pos-panel .btn-success { border-radius: 12px; box-shadow: 0 10px 20px rgba(34, 197, 94, 0.25); }
.pos-panel .btn-outline-light,
.pos-panel .btn-outline-light:hover { border-radius: 12px; }
.pos-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.75rem; padding: 2.5rem 1.25rem; border-radius: 18px; border: 1px dashed rgba(148, 163, 184, 0.4); color: #64748b; background: rgba(241, 245, 249, 0.5); text-align: center; }
.pos-empty i { font-size: 2.2rem; color: #94a3b8; }
@media (max-width: 991.98px) {
    .pos-content { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    .pos-panel { padding: 1.25rem; }
    .pos-checkout-panel { order: -1; }
}
@media (max-width: 575.98px) {
    .pos-summary { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    .pos-summary-card { padding: 1rem; border-radius: 14px; }
    .pos-product-grid { grid-template-columns: repeat(1, minmax(0, 1fr)); }
    .pos-cart-table thead { display: none; }
    .pos-cart-table tbody tr { display: grid; gap: 0.75rem; padding: 0.75rem; border-bottom: 1px solid rgba(148, 163, 184, 0.25); }
    .pos-cart-table td { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; border: none !important; }
    .pos-cart-table td::before { content: attr(data-label); font-weight: 600; color: #64748b; }
}
</style>

