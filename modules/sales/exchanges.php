<?php
/**
 * صفحة إدارة الاستبدالات - النظام الجديد
 * New Exchange Management Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// Get base path for API calls
$basePath = getBasePath();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">
                <i class="bi bi-arrow-left-right me-2"></i>إنشاء استبدال
            </h2>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>نموذج إنشاء استبدال</h5>
                </div>
                <div class="card-body">
                    <form id="exchangeRequestForm">
                        <!-- Step 1: Customer Selection -->
                        <div class="mb-4">
                            <h5 class="mb-3"><i class="bi bi-person me-2"></i>اختيار العميل</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="customerSearch" class="form-label">البحث عن العميل</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="customerSearch" 
                                           placeholder="ابحث بالاسم أو رقم الهاتف..."
                                           autocomplete="off">
                                    <div id="customerDropdown" class="list-group mt-2" style="display: none; max-height: 300px; overflow-y: auto; position: absolute; z-index: 1000; width: 100%;"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">العميل المحدد</label>
                                    <div id="selectedCustomer" class="alert alert-info" style="display: none;">
                                        <strong id="selectedCustomerName"></strong><br>
                                        <small id="selectedCustomerInfo"></small>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="customerId" name="customer_id">
                        </div>
                        
                        <!-- Step 2: Split View - Return Items and Replacement Items -->
                        <div class="row mb-4" id="exchangeSections" style="display: none;">
                            <!-- Left: Return Items (from purchase history) -->
                            <div class="col-md-6">
                                <div class="card border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <h6 class="mb-0"><i class="bi bi-arrow-left me-2"></i>المنتجات المراد إرجاعها</h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="purchaseHistoryLoading" class="text-center" style="display: none;">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">جاري التحميل...</span>
                                            </div>
                                        </div>
                                        <div id="purchaseHistoryTable" class="table-responsive"></div>
                                        
                                        <div class="mt-3" id="selectedReturnItemsSection" style="display: none;">
                                            <h6>المنتجات المحددة للإرجاع:</h6>
                                            <div id="selectedReturnItemsList" class="list-group"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right: Replacement Items (from car inventory) -->
                            <div class="col-md-6">
                                <div class="card border-success">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="mb-0"><i class="bi bi-arrow-right me-2"></i>المنتجات البديلة (من مخزن السيارة)</h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="carInventoryLoading" class="text-center" style="display: none;">
                                            <div class="spinner-border text-success" role="status">
                                                <span class="visually-hidden">جاري التحميل...</span>
                                            </div>
                                        </div>
                                        <div id="carInventoryTable" class="table-responsive"></div>
                                        
                                        <div class="mt-3" id="selectedReplacementItemsSection" style="display: none;">
                                            <h6>المنتجات المحددة للاستبدال:</h6>
                                            <div id="selectedReplacementItemsList" class="list-group"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Price Difference Summary -->
                        <div class="mb-4" id="priceDifferenceSection" style="display: none;">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-calculator me-2"></i>ملخص الفرق المالي</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <strong>قيمة المنتجات المرجعة:</strong>
                                            <div class="h5 text-primary" id="oldValueDisplay">0.00 ج.م</div>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>قيمة المنتجات البديلة:</strong>
                                            <div class="h5 text-success" id="newValueDisplay">0.00 ج.م</div>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>الفرق:</strong>
                                            <div class="h5" id="differenceDisplay">0.00 ج.م</div>
                                        </div>
                                    </div>
                                    <div id="differenceNote" class="alert alert-info mt-3" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Notes -->
                        <div class="mb-4">
                            <label for="exchangeNotes" class="form-label">ملاحظات (اختياري)</label>
                            <textarea class="form-control" 
                                      id="exchangeNotes" 
                                      name="notes" 
                                      rows="3" 
                                      placeholder="أضف أي ملاحظات إضافية..."></textarea>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="d-flex justify-content-end">
                            <button type="button" 
                                    class="btn btn-primary btn-lg" 
                                    id="submitExchangeRequest"
                                    disabled>
                                <i class="bi bi-send me-2"></i>إرسال طلب الاستبدال
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const basePath = '<?php echo $basePath; ?>';
let selectedCustomerId = null;
let selectedSalesRepId = null;
let purchaseHistory = [];
let carInventory = [];
let selectedReturnItems = [];
let selectedReplacementItems = [];

// Customer Search (same as returns)
let customerSearchTimeout;
document.getElementById('customerSearch').addEventListener('input', function() {
    clearTimeout(customerSearchTimeout);
    const searchTerm = this.value.trim();
    
    if (searchTerm.length < 2) {
        document.getElementById('customerDropdown').style.display = 'none';
        return;
    }
    
    customerSearchTimeout = setTimeout(() => {
        fetchCustomers(searchTerm);
    }, 300);
});

function fetchCustomers(search = '') {
    const url = basePath + '/api/exchange_requests.php?action=get_customers' + (search ? '&search=' + encodeURIComponent(search) : '');
    
    fetch(url, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayCustomerDropdown(data.customers);
        } else {
            console.error('Error fetching customers:', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function displayCustomerDropdown(customers) {
    const dropdown = document.getElementById('customerDropdown');
    
    if (customers.length === 0) {
        dropdown.innerHTML = '<div class="list-group-item">لا توجد نتائج</div>';
        dropdown.style.display = 'block';
        return;
    }
    
    let html = '';
    customers.forEach(customer => {
        const balanceText = customer.debt > 0 
            ? `دين: ${parseFloat(customer.debt).toFixed(2)} ج.م`
            : customer.credit > 0 
                ? `رصيد دائن: ${parseFloat(customer.credit).toFixed(2)} ج.م`
                : 'صفر';
        
        html += `
            <a href="#" class="list-group-item list-group-item-action" onclick="selectCustomer(${customer.id}, '${customer.name.replace(/'/g, "\\'")}', ${customer.debt}, ${customer.credit}); return false;">
                <div class="d-flex w-100 justify-content-between">
                    <h6 class="mb-1">${customer.name}</h6>
                    <small>${balanceText}</small>
                </div>
                ${customer.phone ? '<p class="mb-1 small text-muted">' + customer.phone + '</p>' : ''}
            </a>
        `;
    });
    
    dropdown.innerHTML = html;
    dropdown.style.display = 'block';
}

function selectCustomer(customerId, customerName, debt, credit) {
    selectedCustomerId = customerId;
    document.getElementById('customerId').value = customerId;
    document.getElementById('customerSearch').value = customerName;
    document.getElementById('customerDropdown').style.display = 'none';
    
    const selectedDiv = document.getElementById('selectedCustomer');
    const nameDiv = document.getElementById('selectedCustomerName');
    const infoDiv = document.getElementById('selectedCustomerInfo');
    
    nameDiv.textContent = customerName;
    const balanceText = debt > 0 
        ? `دين: ${parseFloat(debt).toFixed(2)} ج.م`
        : credit > 0 
            ? `رصيد دائن: ${parseFloat(credit).toFixed(2)} ج.م`
            : 'صفر';
    infoDiv.textContent = balanceText;
    
    selectedDiv.style.display = 'block';
    
    // Load purchase history and car inventory
    loadPurchaseHistory(customerId);
    loadCarInventory();
}

function loadPurchaseHistory(customerId) {
    const loadingDiv = document.getElementById('purchaseHistoryLoading');
    const tableDiv = document.getElementById('purchaseHistoryTable');
    const sectionDiv = document.getElementById('exchangeSections');
    
    loadingDiv.style.display = 'block';
    tableDiv.innerHTML = '';
    sectionDiv.style.display = 'block';
    
    fetch(basePath + '/api/exchange_requests.php?action=get_purchase_history&customer_id=' + customerId, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        loadingDiv.style.display = 'none';
        
        if (data.success) {
            purchaseHistory = data.purchase_history;
            displayPurchaseHistory(purchaseHistory);
        } else {
            tableDiv.innerHTML = '<div class="alert alert-warning">' + (data.message || 'لا توجد مشتريات') + '</div>';
        }
    })
    .catch(error => {
        loadingDiv.style.display = 'none';
        tableDiv.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل سجل المشتريات</div>';
        console.error('Error:', error);
    });
}

function displayPurchaseHistory(history) {
    const tableDiv = document.getElementById('purchaseHistoryTable');
    
    if (history.length === 0) {
        tableDiv.innerHTML = '<div class="alert alert-info">لا توجد مشتريات متاحة</div>';
        return;
    }
    
    let html = `
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th>المنتج</th>
                    <th>المتبقي</th>
                    <th>السعر</th>
                    <th>إجراء</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    history.forEach(item => {
        html += `
            <tr>
                <td>${item.product_name}</td>
                <td>${parseFloat(item.quantity_remaining).toFixed(2)}</td>
                <td>${parseFloat(item.unit_price).toFixed(2)} ج.م</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="addToReturnItems(${item.invoice_item_id}, ${item.product_id}, '${item.product_name.replace(/'/g, "\\'")}', ${item.quantity_remaining}, ${item.unit_price}, '${(item.batch_numbers || '').replace(/'/g, "\\'")}')">
                        <i class="bi bi-plus"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
    `;
    
    tableDiv.innerHTML = html;
}

function loadCarInventory() {
    const loadingDiv = document.getElementById('carInventoryLoading');
    const tableDiv = document.getElementById('carInventoryTable');
    
    loadingDiv.style.display = 'block';
    tableDiv.innerHTML = '';
    
    fetch(basePath + '/api/exchange_requests.php?action=get_car_inventory', {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        loadingDiv.style.display = 'none';
        
        if (data.success) {
            carInventory = data.inventory;
            displayCarInventory(carInventory);
            } else {
            tableDiv.innerHTML = '<div class="alert alert-warning">' + (data.message || 'لا يوجد مخزون') + '</div>';
        }
    })
    .catch(error => {
        loadingDiv.style.display = 'none';
        tableDiv.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل مخزون السيارة</div>';
        console.error('Error:', error);
    });
}

function displayCarInventory(inventory) {
    const tableDiv = document.getElementById('carInventoryTable');
    
    if (inventory.length === 0) {
        tableDiv.innerHTML = '<div class="alert alert-info">لا يوجد مخزون متاح</div>';
        return;
    }
    
    let html = `
        <table class="table table-sm table-bordered">
            <thead class="table-light">
                <tr>
                    <th>المنتج</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                    <th>إجراء</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    inventory.forEach(item => {
        html += `
            <tr>
                <td>${item.product_name}</td>
                <td>${parseFloat(item.quantity).toFixed(2)}</td>
                <td>${parseFloat(item.unit_price).toFixed(2)} ج.م</td>
                <td>
                    <button class="btn btn-sm btn-success" onclick="addToReplacementItems(${item.product_id}, '${item.product_name.replace(/'/g, "\\'")}', ${item.quantity}, ${item.unit_price}, '${(item.batch_number || '').replace(/'/g, "\\'")}')">
                        <i class="bi bi-plus"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
    `;
    
    tableDiv.innerHTML = html;
}

function addToReturnItems(invoiceItemId, productId, productName, maxQuantity, unitPrice, batchNumbers) {
    const existing = selectedReturnItems.find(item => item.invoice_item_id === invoiceItemId);
    if (existing) {
        alert('هذا المنتج مضاف بالفعل');
        return;
    }
    
    const quantity = prompt(`أدخل الكمية المراد إرجاعها (الحد الأقصى: ${parseFloat(maxQuantity).toFixed(2)})`);
    if (!quantity || parseFloat(quantity) <= 0) {
        return;
    }
    
    const qty = parseFloat(quantity);
    if (qty > maxQuantity + 0.0001) {
        alert(`الكمية المدخلة (${qty.toFixed(2)}) تتجاوز الكمية المتاحة (${parseFloat(maxQuantity).toFixed(2)})`);
        return;
    }
    
    const total = qty * unitPrice;
    
    const item = {
        invoice_item_id: invoiceItemId,
        product_id: productId,
        product_name: productName,
        quantity: qty,
        unit_price: unitPrice,
        total_price: total,
        batch_numbers: batchNumbers
    };
    
    selectedReturnItems.push(item);
    updateSelectedItems();
    calculatePriceDifference();
}

function addToReplacementItems(productId, productName, maxQuantity, unitPrice, batchNumber) {
    const existing = selectedReplacementItems.find(item => item.product_id === productId);
    if (existing) {
        alert('هذا المنتج مضاف بالفعل');
        return;
    }
    
    const quantity = prompt(`أدخل الكمية (الحد الأقصى: ${parseFloat(maxQuantity).toFixed(2)})`);
    if (!quantity || parseFloat(quantity) <= 0) {
        return;
    }
    
    const qty = parseFloat(quantity);
    if (qty > maxQuantity + 0.0001) {
        alert(`الكمية المدخلة (${qty.toFixed(2)}) تتجاوز الكمية المتاحة (${parseFloat(maxQuantity).toFixed(2)})`);
        return;
    }
    
    const total = qty * unitPrice;
    
    const item = {
        product_id: productId,
        product_name: productName,
        quantity: qty,
        unit_price: unitPrice,
        total_price: total,
        batch_number: batchNumber
    };
    
    selectedReplacementItems.push(item);
    updateSelectedItems();
    calculatePriceDifference();
}

function updateSelectedItems() {
    // Update return items list
    const returnListDiv = document.getElementById('selectedReturnItemsList');
    const returnSection = document.getElementById('selectedReturnItemsSection');
    
    if (selectedReturnItems.length === 0) {
        returnSection.style.display = 'none';
        returnListDiv.innerHTML = '';
    } else {
        returnSection.style.display = 'block';
        let html = '';
        selectedReturnItems.forEach((item, index) => {
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${item.product_name}</strong><br>
                        <small>الكمية: ${item.quantity.toFixed(2)} | السعر: ${item.unit_price.toFixed(2)} ج.م | الإجمالي: ${item.total_price.toFixed(2)} ج.م</small>
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="removeReturnItem(${index})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
        });
        returnListDiv.innerHTML = html;
    }
    
    // Update replacement items list
    const replacementListDiv = document.getElementById('selectedReplacementItemsList');
    const replacementSection = document.getElementById('selectedReplacementItemsSection');
    
    if (selectedReplacementItems.length === 0) {
        replacementSection.style.display = 'none';
        replacementListDiv.innerHTML = '';
    } else {
        replacementSection.style.display = 'block';
        let html = '';
        selectedReplacementItems.forEach((item, index) => {
            html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${item.product_name}</strong><br>
                        <small>الكمية: ${item.quantity.toFixed(2)} | السعر: ${item.unit_price.toFixed(2)} ج.م | الإجمالي: ${item.total_price.toFixed(2)} ج.م</small>
                    </div>
                    <button class="btn btn-sm btn-danger" onclick="removeReplacementItem(${index})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `;
        });
        replacementListDiv.innerHTML = html;
    }
}

function removeReturnItem(index) {
    selectedReturnItems.splice(index, 1);
    updateSelectedItems();
    calculatePriceDifference();
}

function removeReplacementItem(index) {
    selectedReplacementItems.splice(index, 1);
    updateSelectedItems();
    calculatePriceDifference();
}

function calculatePriceDifference() {
    const oldValue = selectedReturnItems.reduce((sum, item) => sum + item.total_price, 0);
    const newValue = selectedReplacementItems.reduce((sum, item) => sum + item.total_price, 0);
    const difference = newValue - oldValue;
    
    document.getElementById('oldValueDisplay').textContent = oldValue.toFixed(2) + ' ج.م';
    document.getElementById('newValueDisplay').textContent = newValue.toFixed(2) + ' ج.م';
    
    const differenceDiv = document.getElementById('differenceDisplay');
    const noteDiv = document.getElementById('differenceNote');
    const priceSection = document.getElementById('priceDifferenceSection');
    const submitBtn = document.getElementById('submitExchangeRequest');
    
    if (selectedReturnItems.length > 0 && selectedReplacementItems.length > 0) {
        priceSection.style.display = 'block';
        submitBtn.disabled = false;
        
        if (difference > 0) {
            differenceDiv.textContent = '+' + difference.toFixed(2) + ' ج.م';
            differenceDiv.className = 'h5 text-danger';
            noteDiv.textContent = 'المنتج البديل أغلى - سيتم إضافة ' + difference.toFixed(2) + ' ج.م لدين العميل';
            noteDiv.className = 'alert alert-warning mt-3';
        } else if (difference < 0) {
            differenceDiv.textContent = difference.toFixed(2) + ' ج.م';
            differenceDiv.className = 'h5 text-success';
            noteDiv.textContent = 'المنتج البديل أرخص - سيتم إضافة ' + Math.abs(difference).toFixed(2) + ' ج.م لرصيد العميل الدائن';
            noteDiv.className = 'alert alert-success mt-3';
        } else {
            differenceDiv.textContent = '0.00 ج.م';
            differenceDiv.className = 'h5 text-secondary';
            noteDiv.textContent = 'لا يوجد فرق مالي';
            noteDiv.className = 'alert alert-info mt-3';
        }
        noteDiv.style.display = 'block';
    } else {
        priceSection.style.display = 'none';
        submitBtn.disabled = true;
    }
}

// Submit Exchange Request
document.getElementById('submitExchangeRequest').addEventListener('click', function() {
    if (!selectedCustomerId || selectedReturnItems.length === 0 || selectedReplacementItems.length === 0) {
        alert('يرجى اختيار عميل ومنتجات للإرجاع والاستبدال');
        return;
    }
    
    if (!confirm('هل أنت متأكد من إرسال طلب الاستبدال؟')) {
        return;
    }
    
    const btn = this;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإرسال...';
    
    const notes = document.getElementById('exchangeNotes').value.trim();
    
    fetch(basePath + '/api/exchange_requests.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            action: 'create',
            customer_id: selectedCustomerId,
            return_items: selectedReturnItems,
            replacement_items: selectedReplacementItems,
            notes: notes
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم إنشاء الاستبدال بنجاح!\nرقم الاستبدال: ' + data.exchange_number + '\n' + (data.balance_note || ''));
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
        alert('حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    });
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('customerDropdown');
    const searchInput = document.getElementById('customerSearch');
    
    if (!dropdown.contains(event.target) && event.target !== searchInput) {
        dropdown.style.display = 'none';
    }
});
</script>
