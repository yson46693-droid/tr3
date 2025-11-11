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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-register me-2"></i>نقطة البيع المحلية</h2>
    <div>
        <span class="badge bg-info me-2">المخزن: <?php echo htmlspecialchars($mainWarehouse['name']); ?></span>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
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

<div class="row">
    <!-- قسم المنتجات -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-box-seam me-2"></i>المنتجات المتاحة</h5>
            </div>
            <div class="card-body">
                <!-- البحث -->
                <div class="mb-3">
                    <input type="text" class="form-control" id="productSearch" placeholder="ابحث عن منتج...">
                </div>
                
                <!-- قائمة المنتجات -->
                <div class="row g-2" id="productsGrid">
                    <?php foreach ($products as $product): ?>
                        <div class="col-md-4 col-sm-6 product-item" 
                             data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>"
                             data-category="<?php echo htmlspecialchars(strtolower($product['category'] ?? '')); ?>">
                            <div class="card h-100 product-card" 
                                 onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>', <?php echo $product['unit_price']; ?>, <?php echo $product['quantity']; ?>, '<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>')">
                                <div class="card-body">
                                    <h6 class="card-title mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                    <p class="text-muted small mb-2"><?php echo htmlspecialchars($product['category'] ?? 'عام'); ?></p>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-primary fw-bold"><?php echo formatCurrency($product['unit_price']); ?></span>
                                        <span class="badge bg-success">متاح: <?php echo number_format($product['quantity'], 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($products)): ?>
                        <div class="col-12 text-center text-muted py-5">
                            <i class="bi bi-box-seam" style="font-size: 3rem;"></i>
                            <p class="mt-3">لا توجد منتجات متاحة في المخزن</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- قسم السلة وإتمام البيع -->
    <div class="col-lg-4">
        <div class="card shadow-sm sticky-top" style="top: 20px;">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-cart me-2"></i>سلة التسوق</h5>
            </div>
            <div class="card-body">
                <form id="posForm" method="POST">
                    <input type="hidden" name="action" value="complete_sale">
                    <input type="hidden" name="items" id="cartItems">
                    
                    <!-- اختيار العميل -->
                    <div class="mb-3">
                        <label class="form-label">العميل <span class="text-danger">*</span></label>
                        <select class="form-select" name="customer_id" id="customerSelect" required>
                            <option value="">اختر العميل</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                    <?php if ($customer['phone']): ?>
                                        - <?php echo htmlspecialchars($customer['phone']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">أو <a href="#" data-bs-toggle="modal" data-bs-target="#addCustomerModal">إضافة عميل جديد</a></small>
                    </div>
                    
                    <!-- عناصر السلة -->
                    <div id="cartItemsList" class="mb-3" style="max-height: 300px; overflow-y: auto;">
                        <div class="text-center text-muted py-3">
                            <i class="bi bi-cart-x" style="font-size: 2rem;"></i>
                            <p class="mt-2 mb-0">السلة فارغة</p>
                        </div>
                    </div>
                    
                    <!-- الملخص -->
                    <div class="border-top pt-3 mb-3">
                        <div class="d-flex justify-content-between mb-2">
                            <strong>المجموع الفرعي:</strong>
                            <span id="subtotal">0.00 ج.م</span>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">الخصم (ج.م)</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" 
                                   name="discount_amount" id="discountAmount" value="0" min="0" oninput="calculateTotal()">
                        </div>
                        <div class="d-flex justify-content-between">
                            <h5>الإجمالي:</h5>
                            <h5 class="text-success" id="totalAmount">0.00 ج.م</h5>
                        </div>
                    </div>
                    
                    <!-- الملاحظات -->
                    <div class="mb-3">
                        <label class="form-label small">ملاحظات</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="2"></textarea>
                    </div>
                    
                    <!-- أزرار الإجراءات -->
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-secondary" onclick="clearCart()">
                            <i class="bi bi-x-circle me-2"></i>مسح السلة
                        </button>
                        <button type="submit" class="btn btn-success btn-lg" id="completeSaleBtn" disabled>
                            <i class="bi bi-check-circle me-2"></i>إتمام البيع
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة عميل جديد -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
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
                        <input type="number" class="form-control" name="balance" value="0" min="0" step="0">
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

// البحث في المنتجات
document.getElementById('productSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const productItems = document.querySelectorAll('.product-item');
    
    productItems.forEach(item => {
        const name = item.getAttribute('data-name');
        const category = item.getAttribute('data-category');
        
        if (name.includes(searchTerm) || category.includes(searchTerm)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
});

// إضافة منتج للسلة
function addToCart(productId, productName, unitPrice, availableQuantity, unit) {
    const existingItem = cart.find(item => item.product_id === productId);
    
    if (existingItem) {
        if (existingItem.quantity + 1 > availableQuantity) {
            alert('الكمية المتاحة غير كافية');
            return;
        }
        existingItem.quantity += 1;
    } else {
        cart.push({
            product_id: productId,
            name: productName,
            unit_price: unitPrice,
            quantity: 1,
            available_quantity: availableQuantity,
            unit: unit
        });
    }
    
    updateCartDisplay();
}

// تحديث عرض السلة
function updateCartDisplay() {
    const cartItemsList = document.getElementById('cartItemsList');
    const subtotalEl = document.getElementById('subtotal');
    const totalEl = document.getElementById('totalAmount');
    const cartItemsInput = document.getElementById('cartItems');
    const completeSaleBtn = document.getElementById('completeSaleBtn');
    
    if (cart.length === 0) {
        cartItemsList.innerHTML = `
            <div class="text-center text-muted py-3">
                <i class="bi bi-cart-x" style="font-size: 2rem;"></i>
                <p class="mt-2 mb-0">السلة فارغة</p>
            </div>
        `;
        cartItemsInput.value = '[]';
        completeSaleBtn.disabled = true;
        calculateTotal();
        return;
    }
    
    let html = '';
    let subtotal = 0;
    
    cart.forEach((item, index) => {
        const itemTotal = item.quantity * item.unit_price;
        subtotal += itemTotal;
        
        html += `
            <div class="card mb-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h6 class="mb-0 small">${item.name}</h6>
                            <small class="text-muted">${formatCurrency(item.unit_price)} × ${item.quantity} ${item.unit}</small>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFromCart(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                    <div class="input-group input-group-sm">
                        <button class="btn btn-outline-secondary" type="button" onclick="updateQuantity(${index}, -1)">-</button>
                        <input type="number" class="form-control text-center" value="${item.quantity}" 
                               min="1" max="${item.available_quantity}" 
                               onchange="setQuantity(${index}, this.value)">
                        <button class="btn btn-outline-secondary" type="button" onclick="updateQuantity(${index}, 1)">+</button>
                        <span class="input-group-text">${formatCurrency(itemTotal)}</span>
                    </div>
                </div>
            </div>
        `;
    });
    
    cartItemsList.innerHTML = html;
    cartItemsInput.value = JSON.stringify(cart);
    subtotalEl.textContent = formatCurrency(subtotal);
    completeSaleBtn.disabled = false;
    calculateTotal();
}

// تحديث الكمية
function updateQuantity(index, change) {
    if (cart[index]) {
        const newQuantity = cart[index].quantity + change;
        if (newQuantity >= 1 && newQuantity <= cart[index].available_quantity) {
            cart[index].quantity = newQuantity;
            updateCartDisplay();
        }
    }
}

function setQuantity(index, quantity) {
    if (cart[index]) {
        const qty = parseInt(quantity);
        if (qty >= 1 && qty <= cart[index].available_quantity) {
            cart[index].quantity = qty;
            updateCartDisplay();
        } else {
            alert(`الكمية يجب أن تكون بين 1 و ${cart[index].available_quantity}`);
            updateCartDisplay();
        }
    }
}

// إزالة من السلة
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
}

// مسح السلة
function clearCart() {
    if (confirm('هل أنت متأكد من مسح السلة؟')) {
        cart = [];
        updateCartDisplay();
    }
}

// حساب الإجمالي
function calculateTotal() {
    const subtotal = cart.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
    const discount = parseFloat(document.getElementById('discountAmount').value) || 0;
    const total = subtotal - discount;
    
    document.getElementById('totalAmount').textContent = formatCurrency(Math.max(0, total));
}

// تنسيق العملة
function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP'
    }).format(amount);
}

// تحديث قائمة العملاء بعد الإضافة
<?php if ($success && isset($_POST['action']) && $_POST['action'] === 'create_customer'): ?>
    // إعادة تحميل الصفحة بعد إضافة عميل جديد
    setTimeout(() => {
        window.location.reload();
    }, 1000);
<?php endif; ?>
</script>

<style>
.product-card {
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
}

.product-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.product-card:active {
    transform: translateY(-2px);
}

#cartItemsList {
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    padding: 10px;
}
</style>

