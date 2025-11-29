<?php
/**
 * صفحة إدارة المرتجعات - النظام الجديد
 * New Returns Management Page
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

// Auto-run migration to add invoice_item_id column if needed
try {
    $columnCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'invoice_item_id'");
    if (empty($columnCheck)) {
        // Add invoice_item_id column
        try {
            $db->execute("ALTER TABLE `return_items` ADD COLUMN `invoice_item_id` int(11) DEFAULT NULL AFTER `sale_item_id`");
        } catch (Throwable $e) {
            // Column might already exist or table structure is different
            error_log("Migration: Could not add invoice_item_id: " . $e->getMessage());
        }
        
        // Add index
        try {
            $db->execute("ALTER TABLE `return_items` ADD INDEX `idx_invoice_item_id` (`invoice_item_id`)");
        } catch (Throwable $e) {
            error_log("Migration: Could not add index: " . $e->getMessage());
        }
        
        // Add foreign key
        try {
            $db->execute("ALTER TABLE `return_items` ADD CONSTRAINT `return_items_ibfk_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`id`) ON DELETE SET NULL");
        } catch (Throwable $e) {
            error_log("Migration: Could not add foreign key: " . $e->getMessage());
        }
    }
    
    // Check and add batch_number_id if needed
    $batchColCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'batch_number_id'");
    if (empty($batchColCheck)) {
        try {
            $db->execute("ALTER TABLE `return_items` ADD COLUMN `batch_number_id` int(11) DEFAULT NULL AFTER `invoice_item_id`");
        } catch (Throwable $e) {
            error_log("Migration: Could not add batch_number_id: " . $e->getMessage());
        }
    }
    
    // Check and add batch_number if needed
    $batchNumCheck = $db->queryOne("SHOW COLUMNS FROM return_items LIKE 'batch_number'");
    if (empty($batchNumCheck)) {
        try {
            $db->execute("ALTER TABLE `return_items` ADD COLUMN `batch_number` varchar(100) DEFAULT NULL AFTER `batch_number_id`");
        } catch (Throwable $e) {
            error_log("Migration: Could not add batch_number: " . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    error_log("Migration error: " . $e->getMessage());
}

// Get base path for API calls
$basePath = getBasePath();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">
                <i class="bi bi-arrow-counterclockwise me-2"></i>إنشاء طلب مرتجع
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

            <!-- Dynamic Success Message -->
            <div id="dynamicSuccessAlert" class="alert alert-success alert-dismissible fade show" role="alert" style="display: none;">
                <i class="bi bi-check-circle-fill me-2"></i>
                <span id="successMessageText"></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            
            <!-- Dynamic Error Message -->
            <div id="dynamicErrorAlert" class="alert alert-danger alert-dismissible fade show" role="alert" style="display: none;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <span id="errorMessageText"></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-cart-arrow-down me-2"></i>نموذج إنشاء طلب مرتجع</h5>
        </div>
        <div class="card-body">
                    <form id="returnRequestForm">
                        <!-- Step 1: Customer Selection -->
                        <div class="mb-4">
                            <h5 class="mb-3"><i class="bi bi-person me-2"></i>اختيار العميل</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="customerSearch" class="form-label">ابحث واختر العميل</label>
                                    <div class="position-relative">
                                        <input type="text" 
                                               class="form-control" 
                                               id="customerSearch" 
                                               placeholder="ابحث بالاسم أو رقم الهاتف..."
                                               autocomplete="off">
                                        <div id="customerDropdown" class="list-group position-absolute w-100 mt-1" 
                                             style="display: none; max-height: 400px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid #dee2e6; border-radius: 0.375rem;">
                                        </div>
                                    </div>
                                    <small class="text-muted">ابدأ الكتابة للبحث أو انقر لعرض جميع العملاء</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">العميل المحدد</label>
                                    <div id="selectedCustomer" class="alert alert-info" style="display: none;">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong id="selectedCustomerName"></strong><br>
                                                <small id="selectedCustomerInfo"></small>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="resetReturnForm()" title="تغيير العميل">
                                                <i class="bi bi-x-circle"></i> تغيير
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="customerId" name="customer_id">
                        </div>
                        
                        <!-- Step 2: Purchase History -->
                        <div class="mb-4" id="purchaseHistorySection" style="display: none;">
                            <h5 class="mb-3"><i class="bi bi-clock-history me-2"></i>سجل المشتريات</h5>
                            <div id="purchaseHistoryLoading" class="text-center" style="display: none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">جاري التحميل...</span>
                            </div>
                        </div>
                            <div id="purchaseHistoryTable" class="table-responsive"></div>
                    </div>
                    
                        <!-- Step 3: Return Items Selection -->
                        <div class="mb-4" id="returnItemsSection" style="display: none;">
                            <h5 class="mb-3"><i class="bi bi-list-check me-2"></i>المنتجات المراد إرجاعها</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>المنتج</th>
                                            <th>الكمية المشتراة</th>
                                            <th>الكمية المرجعة</th>
                                            <th>المتبقي</th>
                                            <th>سعر الوحدة</th>
                                            <th>الإجمالي</th>
                                            <th>رقم التشغيلة</th>
                                            <th>إجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody id="returnItemsTableBody">
                                        <!-- Items will be added here dynamically -->
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-primary">
                                            <th colspan="5" class="text-end">الإجمالي:</th>
                                            <th id="totalReturnAmount">0.00</th>
                                            <th colspan="2"></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Step 4: Notes -->
                        <div class="mb-4">
                            <label for="returnNotes" class="form-label">ملاحظات (اختياري)</label>
                            <textarea class="form-control" 
                                      id="returnNotes" 
                                      name="notes" 
                                      rows="3" 
                                      placeholder="أضف أي ملاحظات إضافية..."></textarea>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="d-flex justify-content-end">
                            <button type="button" 
                                    class="btn btn-primary btn-lg" 
                                    id="submitReturnRequest"
                                    disabled>
                                <i class="bi bi-send me-2"></i>إرسال طلب المرتجع
                        </button>
                </div>
            </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Requests Table -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>طلبات المرتجع والاستبدال الأخيرة
                    </h5>
                </div>
                <div class="card-body">
                    <div id="recentRequestsLoading" class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">جاري التحميل...</span>
                        </div>
                    </div>
                    <div id="recentRequestsTable" class="table-responsive"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const basePath = '<?php echo $basePath; ?>';
let selectedCustomerId = null;
let purchaseHistory = [];
let selectedReturnItems = [];
let allCustomers = [];
let customerSearchInput = null;
let customerDropdown = null;

// Initialize elements after DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    customerSearchInput = document.getElementById('customerSearch');
    customerDropdown = document.getElementById('customerDropdown');
    
    if (!customerSearchInput || !customerDropdown) {
        console.error('Customer search elements not found');
        return;
    }
    
    // Load customers on page load
    loadCustomers();
    
    // Customer Search - Show dropdown on focus or input
    customerSearchInput.addEventListener('focus', function() {
        if (allCustomers.length > 0) {
            displayCustomerDropdown(allCustomers);
        } else {
            loadCustomers();
        }
    });

    customerSearchInput.addEventListener('input', function() {
        const searchTerm = this.value.trim().toLowerCase();
        
        if (searchTerm.length === 0) {
            displayCustomerDropdown(allCustomers);
            return;
        }
        
        const filtered = allCustomers.filter(customer => {
            const nameMatch = customer.name.toLowerCase().includes(searchTerm);
            const phoneMatch = customer.phone && customer.phone.toLowerCase().includes(searchTerm);
            return nameMatch || phoneMatch;
        });
        
        displayCustomerDropdown(filtered);
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        if (customerSearchInput && customerDropdown) {
            if (!customerSearchInput.contains(event.target) && !customerDropdown.contains(event.target)) {
                customerDropdown.style.display = 'none';
            }
        }
    });
});


function loadCustomers() {
    fetch(basePath + '/api/returns.php?action=get_customers', {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            allCustomers = data.customers;
            if (customerSearchInput === document.activeElement || customerSearchInput.value.trim() !== '') {
                displayCustomerDropdown(data.customers);
            }
        } else {
            console.error('Error fetching customers:', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function displayCustomerDropdown(customers) {
    if (!customerDropdown) return;
    
    if (customers.length === 0) {
        customerDropdown.innerHTML = '<div class="list-group-item text-muted">لا توجد نتائج</div>';
        customerDropdown.style.display = 'block';
        return;
    }
    
    let html = '';
    customers.forEach(customer => {
        const balanceText = customer.debt > 0 
            ? `<span class="badge bg-danger">دين: ${parseFloat(customer.debt).toFixed(2)} ج.م</span>`
            : customer.credit > 0 
                ? `<span class="badge bg-success">رصيد: ${parseFloat(customer.credit).toFixed(2)} ج.م</span>`
                : '<span class="badge bg-secondary">صفر</span>';
        
        const phoneText = customer.phone ? `<br><small class="text-muted"><i class="bi bi-telephone"></i> ${customer.phone}</small>` : '';
        
        html += `
            <a href="#" class="list-group-item list-group-item-action" 
               onclick="selectCustomer(${customer.id}, '${customer.name.replace(/'/g, "\\'")}', ${customer.debt}, ${customer.credit}); return false;"
               style="cursor: pointer;">
                <div class="d-flex w-100 justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h6 class="mb-1">${customer.name}</h6>
                        ${phoneText}
                    </div>
                    <div class="ms-2">
                        ${balanceText}
                    </div>
                </div>
            </a>
        `;
    });
    
    customerDropdown.innerHTML = html;
    customerDropdown.style.display = 'block';
}

function selectCustomer(customerId, customerName, debt, credit) {
    try {
        // إعادة تعيين المنتجات المحددة سابقاً
        selectedReturnItems = [];
        updateReturnItemsTable();
        
        selectedCustomerId = customerId;
        const customerIdInput = document.getElementById('customerId');
        const selectedDiv = document.getElementById('selectedCustomer');
        const nameDiv = document.getElementById('selectedCustomerName');
        const infoDiv = document.getElementById('selectedCustomerInfo');
        
        if (!customerIdInput) {
            console.error('customerId input not found');
            return;
        }
        
        if (!selectedDiv) {
            console.error('selectedCustomer div not found');
            return;
        }
        
        if (!nameDiv) {
            console.error('selectedCustomerName not found');
            return;
        }
        
        if (!infoDiv) {
            console.error('selectedCustomerInfo not found');
            return;
        }
        
        customerIdInput.value = customerId;
        
        if (customerSearchInput) {
            customerSearchInput.value = customerName;
        }
        
        if (customerDropdown) {
            customerDropdown.style.display = 'none';
        }
        
        nameDiv.textContent = customerName;
        const balanceText = debt > 0 
            ? `دين: ${parseFloat(debt).toFixed(2)} ج.م`
            : credit > 0 
                ? `رصيد دائن: ${parseFloat(credit).toFixed(2)} ج.م`
                : 'صفر';
        infoDiv.textContent = balanceText;
        
        selectedDiv.style.display = 'block';
        
        // Load purchase history
        loadPurchaseHistory(customerId);
    } catch (error) {
        console.error('Error in selectCustomer:', error);
        alert('حدث خطأ أثناء اختيار العميل. يرجى المحاولة مرة أخرى.');
    }
}

function resetReturnForm() {
    // إعادة تعيين العميل
    selectedCustomerId = null;
    selectedReturnItems = [];
    purchaseHistory = [];
    
    // إعادة تعيين حقول النموذج
    const customerIdInput = document.getElementById('customerId');
    const customerSearch = document.getElementById('customerSearch');
    const selectedDiv = document.getElementById('selectedCustomer');
    const returnNotes = document.getElementById('returnNotes');
    const purchaseHistorySection = document.getElementById('purchaseHistorySection');
    const returnItemsSection = document.getElementById('returnItemsSection');
    const submitBtn = document.getElementById('submitReturnRequest');
    
    if (customerIdInput) customerIdInput.value = '';
    if (customerSearch) customerSearch.value = '';
    if (selectedDiv) selectedDiv.style.display = 'none';
    if (returnNotes) returnNotes.value = '';
    if (purchaseHistorySection) purchaseHistorySection.style.display = 'none';
    if (returnItemsSection) returnItemsSection.style.display = 'none';
    if (submitBtn) submitBtn.disabled = true;
    
    // إعادة تعيين الجداول
    const purchaseHistoryTable = document.getElementById('purchaseHistoryTable');
    const returnItemsTableBody = document.getElementById('returnItemsTableBody');
    
    if (purchaseHistoryTable) purchaseHistoryTable.innerHTML = '';
    if (returnItemsTableBody) {
        returnItemsTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">لم يتم اختيار أي منتجات</td></tr>';
    }
}

function loadPurchaseHistory(customerId) {
    const loadingDiv = document.getElementById('purchaseHistoryLoading');
    const tableDiv = document.getElementById('purchaseHistoryTable');
    const sectionDiv = document.getElementById('purchaseHistorySection');
    
    loadingDiv.style.display = 'block';
    tableDiv.innerHTML = '';
    sectionDiv.style.display = 'block';
    
    fetch(basePath + '/api/returns.php?action=get_purchase_history&customer_id=' + customerId, {
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
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>المنتج</th>
                    <th>الكمية المشتراة</th>
                    <th>الكمية المرجعة</th>
                    <th>المتبقي</th>
                    <th>سعر الوحدة</th>
                    <th>الإجمالي</th>
                    <th>رقم التشغيلة</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    history.forEach(item => {
        // عرض معلومات الفواتير إذا كانت موجودة
        const invoicesInfo = item.invoices && item.invoices.length > 0 
            ? `<br><small class="text-muted">من فواتير: ${item.invoices.map(inv => inv.invoice_number).join(', ')}</small>`
            : '';
        
        html += `
            <tr>
                <td>
                    <strong>${item.product_name}</strong>
                    <small class="text-muted">(${item.unit || ''})</small>
                    ${invoicesInfo}
                </td>
                <td>${parseFloat(item.quantity_purchased).toFixed(2)}</td>
                <td>${parseFloat(item.quantity_returned).toFixed(2)}</td>
                <td><strong>${parseFloat(item.quantity_remaining).toFixed(2)}</strong></td>
                <td>${parseFloat(item.unit_price).toFixed(2)} ج.م</td>
                <td>${parseFloat(item.total_price).toFixed(2)} ج.م</td>
                <td>${item.batch_numbers || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="addToReturnItems(${item.invoice_item_id}, ${item.product_id}, '${item.product_name.replace(/'/g, "\\'")}', ${item.quantity_remaining}, ${item.unit_price}, '${(item.batch_numbers || '').replace(/'/g, "\\'")}', ${JSON.stringify(item.invoice_item_ids || [item.invoice_item_id]).replace(/'/g, "\\'")})">
                        <i class="bi bi-plus-circle"></i> إضافة
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

function addToReturnItems(invoiceItemId, productId, productName, maxQuantity, unitPrice, batchNumbers, allInvoiceItemIds) {
    // Check if already added - التحقق حسب المنتج وليس حسب invoice_item_id
    const existing = selectedReturnItems.find(item => item.product_id === productId);
    if (existing) {
        alert('هذا المنتج مضاف بالفعل. يرجى إزالته أولاً لإضافة كمية مختلفة.');
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
    
    // استخدام جميع invoice_item_ids إذا كانت متوفرة
    const invoiceItemIds = allInvoiceItemIds ? (Array.isArray(allInvoiceItemIds) ? allInvoiceItemIds : JSON.parse(allInvoiceItemIds)) : [invoiceItemId];
    
    // استخدام أول invoice_item_id كرئيسي والآخرين كاحتياطي
    const primaryInvoiceItemId = invoiceItemIds[0] || invoiceItemId;
    
    const item = {
        invoice_item_id: primaryInvoiceItemId,
        invoice_item_ids: invoiceItemIds, // جميع IDs المرتبطة
        product_id: productId,
        product_name: productName,
        quantity: qty,
        unit_price: unitPrice,
        total_price: total,
        batch_numbers: batchNumbers
    };
    
    selectedReturnItems.push(item);
    updateReturnItemsTable();
}

function updateReturnItemsTable() {
    const tbody = document.getElementById('returnItemsTableBody');
    const totalDiv = document.getElementById('totalReturnAmount');
    const returnSection = document.getElementById('returnItemsSection');
    const submitBtn = document.getElementById('submitReturnRequest');
    
    if (selectedReturnItems.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">لم يتم اختيار أي منتجات</td></tr>';
        returnSection.style.display = 'none';
        submitBtn.disabled = true;
        totalDiv.textContent = '0.00';
        return;
    }
    
    returnSection.style.display = 'block';
    submitBtn.disabled = false;
    
    let html = '';
    let total = 0;
    
    selectedReturnItems.forEach((item, index) => {
        total += item.total_price;
        html += `
            <tr>
                <td>${item.product_name}</td>
                <td>-</td>
                <td>${item.quantity.toFixed(2)}</td>
                <td>-</td>
                <td>${item.unit_price.toFixed(2)} ج.م</td>
                <td>${item.total_price.toFixed(2)} ج.م</td>
                <td>${item.batch_numbers || '-'}</td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="removeReturnItem(${index})">
                        <i class="bi bi-trash"></i>
                        </button>
                                </td>
                            </tr>
        `;
    });
    
    tbody.innerHTML = html;
    totalDiv.textContent = total.toFixed(2) + ' ج.م';
}

function removeReturnItem(index) {
    selectedReturnItems.splice(index, 1);
    updateReturnItemsTable();
}

// Submit Return Request
document.getElementById('submitReturnRequest').addEventListener('click', function() {
    if (!selectedCustomerId || selectedReturnItems.length === 0) {
        alert('يرجى اختيار عميل ومنتجات للإرجاع');
        return;
    }
    
    if (!confirm('هل أنت متأكد من إرسال طلب المرتجع؟')) {
        return;
    }
    
    const btn = this;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإرسال...';
    
    const notes = document.getElementById('returnNotes').value.trim();
    
    fetch(basePath + '/api/returns.php?action=create', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            customer_id: selectedCustomerId,
            items: selectedReturnItems,
            notes: notes
        })
    })
    .then(response => {
        // التحقق من نوع المحتوى أولاً
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('Expected JSON but got:', contentType, text.substring(0, 500));
                throw new Error('استجابة غير صحيحة من الخادم. يرجى المحاولة مرة أخرى.');
            });
        }
        
        // التحقق من حالة الاستجابة
        if (!response.ok) {
            return response.json().then(errorData => {
                throw new Error(errorData.message || 'خطأ في الطلب: ' + response.status);
            }).catch(() => {
                throw new Error('حدث خطأ في الطلب: ' + response.status);
            });
        }
        
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // إظهار رسالة النجاح مع رابط الطباعة
            const printUrl = data.print_url || (basePath + '/print_return_invoice.php?id=' + data.return_id);
            const successMessage = 'تم إنشاء طلب المرتجع بنجاح!<br>رقم المرتجع: <strong>' + data.return_number + '</strong><br>تم إرساله للموافقة<br><br><a href="' + printUrl + '" target="_blank" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-printer me-1"></i>طباعة فاتورة المرتجع</a>';
            const successAlert = document.getElementById('dynamicSuccessAlert');
            const successText = document.getElementById('successMessageText');
            
            if (successAlert && successText) {
                successText.innerHTML = successMessage;
                successAlert.style.display = 'block';
                
                // التمرير إلى أعلى الصفحة لرؤية الرسالة
                window.scrollTo({ top: 0, behavior: 'smooth' });
                
                // إعادة تعيين النموذج للسماح بإنشاء طلب جديد
                resetReturnForm();
                
                // تحديث جدول الطلبات الأخيرة بعد تأخير قصير
                setTimeout(function() {
                    loadRecentRequests();
                }, 500);
            } else {
                // Fallback: استخدام alert إذا لم يتم العثور على العناصر
                alert('تم إنشاء طلب المرتجع بنجاح!\nرقم المرتجع: ' + data.return_number + '\nتم إرساله للموافقة');
                resetReturnForm();
                loadRecentRequests();
            }
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            
            // إظهار رسالة الخطأ في الصفحة
            const errorAlert = document.getElementById('dynamicErrorAlert');
            const errorText = document.getElementById('errorMessageText');
            
            if (errorAlert && errorText) {
                errorText.textContent = 'خطأ: ' + (data.message || 'حدث خطأ غير معروف');
                errorAlert.style.display = 'block';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
        
        // إظهار رسالة الخطأ في الصفحة
        const errorAlert = document.getElementById('dynamicErrorAlert');
        const errorText = document.getElementById('errorMessageText');
        
        let errorMessage = 'حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.';
        if (error.message) {
            errorMessage = error.message;
        }
        
        if (errorAlert && errorText) {
            errorText.textContent = errorMessage;
            errorAlert.style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            alert(errorMessage);
        }
    });
});

// Load recent requests on page load
document.addEventListener('DOMContentLoaded', function() {
    loadRecentRequests();
});

function loadRecentRequests() {
    const loadingDiv = document.getElementById('recentRequestsLoading');
    const tableDiv = document.getElementById('recentRequestsTable');
    
    if (!loadingDiv || !tableDiv) {
        return;
    }
    
    loadingDiv.style.display = 'block';
    tableDiv.innerHTML = '';
    
    fetch(basePath + '/api/returns.php?action=get_recent_requests&limit=20', {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        loadingDiv.style.display = 'none';
        
        if (data.success && data.data) {
            displayRecentRequests(data.data);
        } else {
            tableDiv.innerHTML = '<div class="alert alert-warning">لا توجد طلبات</div>';
        }
    })
    .catch(error => {
        loadingDiv.style.display = 'none';
        tableDiv.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل الطلبات</div>';
        console.error('Error:', error);
    });
}

function displayRecentRequests(data) {
    const tableDiv = document.getElementById('recentRequestsTable');
    
    const allRequests = [];
    
    // Add return requests
    if (data.returns && data.returns.length > 0) {
        data.returns.forEach(req => {
            allRequests.push(req);
        });
    }
    
    // Add exchange requests
    if (data.exchanges && data.exchanges.length > 0) {
        data.exchanges.forEach(req => {
            allRequests.push(req);
        });
    }
    
    // Sort by created_at descending
    allRequests.sort((a, b) => {
        return new Date(b.created_at) - new Date(a.created_at);
    });
    
    if (allRequests.length === 0) {
        tableDiv.innerHTML = '<div class="alert alert-info">لا توجد طلبات حتى الآن</div>';
        return;
    }
    
    let html = `
        <table class="table table-bordered table-hover table-striped">
            <thead class="table-light">
                <tr>
                    <th>نوع الطلب</th>
                    <th>رقم الطلب</th>
                    <th>التاريخ</th>
                    <th>العميل</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                    <th>الملاحظات</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    allRequests.forEach(req => {
        const isReturn = req.type === 'return';
        const requestNumber = isReturn ? req.return_number : req.exchange_number;
        const requestDate = isReturn ? req.return_date : req.exchange_date;
        const amount = isReturn ? req.refund_amount : req.difference_amount;
        const amountLabel = isReturn ? 'مبلغ المرتجع' : 'الفرق';
        
        const statusBadge = getStatusBadge(req.status, req.status_label);
        const typeLabel = isReturn ? '<span class="badge bg-warning text-dark"><i class="bi bi-arrow-counterclockwise"></i> مرتجع</span>' : '<span class="badge bg-info"><i class="bi bi-arrow-left-right"></i> استبدال</span>';
        
        html += `
            <tr>
                <td>${typeLabel}</td>
                <td><strong>${requestNumber}</strong></td>
                <td>${requestDate}</td>
                <td>${req.customer_name || 'غير معروف'}</td>
                <td>${parseFloat(amount).toFixed(2)} ج.م</td>
                <td>${statusBadge}</td>
                <td><small class="text-muted">${req.notes || '-'}</small></td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
    `;
    
    tableDiv.innerHTML = html;
}

function getStatusBadge(status, label) {
    const statusColors = {
        'pending': 'warning',
        'approved': 'success',
        'rejected': 'danger',
        'completed': 'primary',
        'processed': 'info'
    };
    
    const color = statusColors[status] || 'secondary';
    return `<span class="badge bg-${color}">${label || status}</span>`;
}

</script>
