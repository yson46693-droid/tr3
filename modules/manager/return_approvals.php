<?php
/**
 * Manager Return Approvals Page
 * Displays pending return requests for manager approval
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/approval_system.php';
require_once __DIR__ . '/../../includes/returns_system.php';
require_once __DIR__ . '/../../includes/path_helper.php';

$db = db();
$currentUser = getCurrentUser();

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Get pending return requests
$entityColumn = getApprovalsEntityColumn();
$pendingReturns = $db->query(
    "SELECT r.*, c.name as customer_name, c.balance as customer_balance,
            u.full_name as sales_rep_name,
            a.id as approval_id, a.created_at as request_date,
            req.full_name as requested_by_name
     FROM returns r
     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
     LEFT JOIN customers c ON r.customer_id = c.id
     LEFT JOIN users u ON r.sales_rep_id = u.id
     LEFT JOIN users req ON a.requested_by = req.id
     WHERE r.status = 'pending' AND a.status = 'pending'
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?",
    [$perPage, $offset]
);

$totalPending = $db->queryOne(
    "SELECT COUNT(*) as total
     FROM returns r
     INNER JOIN approvals a ON a.type = 'return_request' AND a.{$entityColumn} = r.id
     WHERE r.status = 'pending' AND a.status = 'pending'"
);

$totalPendingCount = (int)($totalPending['total'] ?? 0);
$totalPages = ceil($totalPendingCount / $perPage);

// Get return items for each return
foreach ($pendingReturns as &$return) {
    $return['items'] = $db->query(
        "SELECT ri.*, p.name as product_name, p.unit
         FROM return_items ri
         LEFT JOIN products p ON ri.product_id = p.id
         WHERE ri.return_id = ?
         ORDER BY ri.id",
        [(int)$return['id']]
    );
    
    // Calculate customer debt/credit
    $balance = (float)($return['customer_balance'] ?? 0);
    $return['customer_debt'] = $balance > 0 ? $balance : 0;
    $return['customer_credit'] = $balance < 0 ? abs($balance) : 0;
}
unset($return);

?>

<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0">طلبات المرتجعات المعلقة (<?php echo $totalPendingCount; ?>)</h5>
    </div>
    <div class="card-body">
        <?php if (empty($pendingReturns)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>لا توجد طلبات مرتجعات معلقة
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>رقم المرتجع</th>
                            <th>العميل</th>
                            <th>المندوب</th>
                            <th>المبلغ</th>
                            <th>رصيد العميل</th>
                            <th>المنتجات</th>
                            <th>التاريخ</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingReturns as $return): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($return['return_number']); ?></strong>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($return['customer_name'] ?? 'غير معروف'); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($return['sales_rep_name'] ?? 'غير معروف'); ?>
                                </td>
                                <td>
                                    <strong class="text-primary">
                                        <?php echo number_format((float)$return['refund_amount'], 2); ?> ج.م
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($return['customer_debt'] > 0): ?>
                                        <span class="badge bg-danger">
                                            دين: <?php echo number_format($return['customer_debt'], 2); ?> ج.م
                                        </span>
                                    <?php elseif ($return['customer_credit'] > 0): ?>
                                        <span class="badge bg-success">
                                            رصيد دائن: <?php echo number_format($return['customer_credit'], 2); ?> ج.م
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">صفر</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <?php foreach ($return['items'] as $item): ?>
                                            <div class="mb-1">
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($item['product_name'] ?? 'غير معروف'); ?>
                                                    (<?php echo number_format((float)$item['quantity'], 2); ?>)
                                                    <?php if (!empty($item['batch_number'])): ?>
                                                        <br><small>تشغيلة: <?php echo htmlspecialchars($item['batch_number']); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('Y-m-d', strtotime($return['request_date'])); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-success" 
                                                onclick="approveReturn(<?php echo $return['id']; ?>, event)"
                                                title="موافقة">
                                            <i class="bi bi-check-circle"></i> موافقة
                                        </button>
                                        <button class="btn btn-danger" 
                                                onclick="rejectReturn(<?php echo $return['id']; ?>, event)"
                                                title="رفض">
                                            <i class="bi bi-x-circle"></i> رفض
                                        </button>
                                        <button class="btn btn-info" 
                                                onclick="viewReturnDetails(<?php echo $return['id']; ?>)"
                                                title="تفاصيل">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Page navigation" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=approvals&section=returns&p=<?php echo $pageNum - 1; ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        
                        <?php
                        $startPage = max(1, $pageNum - 2);
                        $endPage = min($totalPages, $pageNum + 2);
                        
                        if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=approvals&section=returns&p=1">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=approvals&section=returns&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="?page=approvals&section=returns&p=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=approvals&section=returns&p=<?php echo $pageNum + 1; ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Return Details Modal -->
<div class="modal fade" id="returnDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل طلب المرتجع</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="returnDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const basePath = '<?php echo getBasePath(); ?>';

// دالة لإظهار رسالة النجاح
function showSuccessMessage(mainMessage, financialNote, itemsReturned, returnNumber) {
    // إنشاء عنصر Toast
    const toastContainer = document.getElementById('toast-container') || createToastContainer();
    
    const toastId = 'success-toast-' + Date.now();
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
            <div class="d-flex">
                <div class="toast-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-check-circle-fill fs-4 me-2"></i>
                        <strong class="me-auto">تمت الموافقة بنجاح!</strong>
                    </div>
                    <div class="small">
                        ${mainMessage.replace(/\n/g, '<br>')}
                    </div>
                    ${financialNote ? `<div class="mt-2 small"><strong>التفاصيل المالية:</strong><br>${financialNote.replace(/\n/g, '<br>')}</div>` : ''}
                    ${itemsReturned > 0 ? `<div class="mt-2 small"><i class="bi bi-box-seam me-1"></i>تم إرجاع ${itemsReturned} منتج(ات) للمخزون</div>` : ''}
                    ${returnNumber ? `<div class="mt-1 small text-white-50">رقم المرتجع: ${returnNumber}</div>` : ''}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement);
    toast.show();
    
    // إزالة العنصر بعد إخفائه
    toastElement.addEventListener('hidden.bs.toast', function() {
        toastElement.remove();
    });
}

// دالة لإظهار رسالة الخطأ - مع دعم Bootstrap Alert كبديل
function showErrorMessage(message) {
    try {
        // محاولة 1: استخدام Bootstrap Toast
        if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
            const toastContainer = document.getElementById('toast-container') || createToastContainer();
            
            const toastId = 'error-toast-' + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
                    <div class="d-flex">
                        <div class="toast-body">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill fs-4 me-2"></i>
                                <strong class="me-auto">خطأ</strong>
                            </div>
                            <div class="mt-2">${message}</div>
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHtml);
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            toastElement.addEventListener('hidden.bs.toast', function() {
                toastElement.remove();
            });
            return;
        }
    } catch (e) {
        console.warn('Bootstrap Toast not available, using Alert fallback:', e);
    }
    
    // محاولة 2: استخدام Bootstrap Alert
    try {
        const alertContainer = document.getElementById('alert-container') || createAlertContainer();
        const alertId = 'error-alert-' + Date.now();
        
        const alertHtml = `
            <div id="${alertId}" class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>❌ خطأ:</strong> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        alertContainer.insertAdjacentHTML('afterbegin', alertHtml);
        
        setTimeout(() => {
            const alertEl = document.getElementById(alertId);
            if (alertEl) {
                alertEl.remove();
            }
        }, 5000);
        return;
    } catch (e) {
        console.warn('Bootstrap Alert not available, using native alert:', e);
    }
    
    // Fallback 3: استخدام Alert الأصلي
    alert('❌ خطأ: ' + message);
}

// إنشاء حاوية Toast إذا لم تكن موجودة
function createToastContainer() {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    return container;
}

// إنشاء حاوية Alert إذا لم تكن موجودة
function createAlertContainer() {
    let container = document.getElementById('alert-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'alert-container';
        container.className = 'position-fixed top-0 start-50 translate-middle-x mt-3';
        container.style.zIndex = '9999';
        container.style.width = '90%';
        container.style.maxWidth = '600px';
        document.body.appendChild(container);
    }
    return container;
}

function approveReturn(returnId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    if (!confirm('هل أنت متأكد من الموافقة على طلب المرتجع؟')) {
        return;
    }
    
    const btn = event ? event.target.closest('button') : null;
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    const requestData = {
        return_id: returnId,
        action: 'approve'
    };
    
    console.log('=== APPROVE RETURN REQUEST ===');
    console.log('Base Path:', basePath);
    console.log('Full URL:', basePath + '/api/returns.php?action=approve');
    console.log('Return ID:', returnId);
    console.log('Request Data:', requestData);
    
    fetch(basePath + '/api/returns.php?action=approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(requestData)
    })
    .then(response => {
        console.log('Response Status:', response.status);
        console.log('Response Headers:', response.headers);
        return response.json();
    })
    .then(data => {
        console.log('Response Data:', data);
        if (data.success) {
            console.log('Approval successful!');
            
            // بناء رسالة النجاح التفصيلية
            let successMsg = '✅ تمت الموافقة على طلب المرتجع بنجاح!\n\n';
            
            if (data.financial_note) {
                successMsg += '📊 التفاصيل المالية:\n' + data.financial_note + '\n\n';
            }
            
            if (data.items_returned && data.items_returned > 0) {
                successMsg += '📦 تم إرجاع ' + data.items_returned + ' منتج(ات) إلى مخزن السيارة\n\n';
            }
            
            if (data.return_number) {
                successMsg += '🔢 رقم المرتجع: ' + data.return_number;
            }
            
            // إظهار رسالة النجاح مباشرة
            alert(successMsg);
            
            // تحديث حالة الزر لإظهار أنه تمت الموافقة
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i>تمت الموافقة';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-secondary');
            }
            
            // إزالة الصف من الجدول بعد الموافقة (بدون إعادة تحميل)
            const row = btn ? btn.closest('tr') : null;
            if (row) {
                // إضافة تأثير fade out
                row.style.transition = 'opacity 0.5s';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                }, 500);
            }
            
            // إعادة تحميل الصفحة تلقائياً بعد 2.5 ثانية
            setTimeout(() => {
                location.reload();
            }, 2500);
        } else {
            console.error('Approval failed:', data.message);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            
            // إظهار رسالة الخطأ مباشرة
            alert('❌ خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('=== APPROVE RETURN ERROR ===');
        console.error('Error type:', error.name);
        console.error('Error message:', error.message);
        console.error('Error stack:', error.stack);
        console.error('Full error:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        // محاولة استخدام Toast، ثم Alert كبديل
        try {
            if (typeof showErrorMessage === 'function') {
                showErrorMessage('حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
            } else {
                alert('❌ حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
            }
        } catch (e) {
            console.error('Error showing error message:', e);
            alert('❌ حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
        }
    });
}

function rejectReturn(returnId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    const notes = prompt('يرجى إدخال سبب الرفض (اختياري):');
    if (notes === null) {
        return; // User cancelled
    }
    
    const btn = event ? event.target.closest('button') : null;
    const originalHTML = btn ? btn.innerHTML : '';
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    fetch(basePath + '/api/returns.php?action=reject', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            notes: notes || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم رفض الطلب بنجاح');
            // إعادة تحميل الصفحة تلقائياً بعد 2.5 ثانية
            setTimeout(() => {
                location.reload();
            }, 2500);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error rejecting return:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    });
}

function viewReturnDetails(returnId) {
    const modal = new bootstrap.Modal(document.getElementById('returnDetailsModal'));
    const content = document.getElementById('returnDetailsContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    modal.show();
    
    fetch(basePath + '/api/returns.php?action=get_return_details&return_id=' + returnId, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.return) {
            const ret = data.return;
            let html = `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>رقم المرتجع:</strong> ${ret.return_number || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>التاريخ:</strong> ${ret.return_date || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>العميل:</strong> ${ret.customer_name || '-'}
                    </div>
                    <div class="col-md-6">
                        <strong>المندوب:</strong> ${ret.sales_rep_name || '-'}
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>المبلغ:</strong> <span class="text-primary">${parseFloat(ret.refund_amount || 0).toFixed(2)} ج.م</span>
                    </div>
                    <div class="col-md-6">
                        <strong>الحالة:</strong> <span class="badge bg-${ret.status === 'approved' ? 'success' : ret.status === 'rejected' ? 'danger' : 'warning'}">${ret.status || '-'}</span>
                    </div>
                </div>
                <hr>
                <h6>المنتجات:</h6>
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>رقم التشغيلة</th>
                            <th>حالة</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            if (ret.items && ret.items.length > 0) {
                ret.items.forEach(item => {
                    html += `
                        <tr>
                            <td>${item.product_name || '-'}</td>
                            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
                            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
                            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
                            <td>${item.batch_number || '-'}</td>
                            <td>${item.is_damaged ? '<span class="badge bg-danger">تالف</span>' : '<span class="badge bg-success">سليم</span>'}</td>
                        </tr>
                    `;
                });
            } else {
                html += '<tr><td colspan="6" class="text-center">لا توجد منتجات</td></tr>';
            }
            
            html += `
                    </tbody>
                </table>
            `;
            
            if (ret.notes) {
                html += `<hr><strong>ملاحظات:</strong><p>${ret.notes}</p>`;
            }
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div class="alert alert-warning">لا يمكن تحميل تفاصيل المرتجع</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        content.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل التفاصيل</div>';
    });
}
</script>

