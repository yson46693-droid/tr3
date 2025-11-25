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
require_once __DIR__ . '/../../includes/return_processor.php';
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
    
    fetch(basePath + '/api/approve_return.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            action: 'approve'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تمت الموافقة بنجاح!\n' + (data.financial_note || ''));
            location.reload();
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error approving return:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('حدث خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
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
    
    fetch(basePath + '/api/approve_return.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            return_id: returnId,
            action: 'reject',
            notes: notes || ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('تم رفض الطلب بنجاح');
            location.reload();
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
    
    // Fetch return details (you can create an API endpoint for this)
    // For now, just show a message
    setTimeout(() => {
        content.innerHTML = '<p>تفاصيل طلب المرتجع رقم: ' + returnId + '</p><p class="text-muted">يمكن إضافة API endpoint لعرض التفاصيل الكاملة</p>';
    }, 500);
}
</script>

