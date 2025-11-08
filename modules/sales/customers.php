<?php
/**
 * صفحة إدارة العملاء للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);

    if ($action === 'collect_debt') {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;

        if ($customerId <= 0) {
            $error = 'معرف العميل غير صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } else {
            $transactionStarted = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $customer = $db->queryOne(
                    "SELECT id, name, balance FROM customers WHERE id = ? FOR UPDATE",
                    [$customerId]
                );

                if (!$customer) {
                    throw new InvalidArgumentException('لم يتم العثور على العميل المطلوب.');
                }

                $currentBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;

                if ($currentBalance <= 0) {
                    throw new InvalidArgumentException('لا توجد ديون نشطة على هذا العميل.');
                }

                if ($amount > $currentBalance) {
                    throw new InvalidArgumentException('المبلغ المدخل أكبر من ديون العميل الحالية.');
                }

                $newBalance = round(max($currentBalance - $amount, 0), 2);

                $db->execute(
                    "UPDATE customers SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );

                logAudit(
                    $currentUser['id'],
                    'collect_customer_debt',
                    'customer',
                    $customerId,
                    null,
                    [
                        'collected_amount'   => $amount,
                        'previous_balance'   => $currentBalance,
                        'new_balance'        => $newBalance,
                    ]
                );

                $db->commit();
                $transactionStarted = false;

                $success = 'تم تحصيل المبلغ بنجاح.';
            } catch (InvalidArgumentException $userError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $error = $userError->getMessage();
            } catch (Throwable $collectionError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('Customer collection error: ' . $collectionError->getMessage());
                $error = 'حدث خطأ أثناء تحصيل المبلغ. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance']) : 0;

        if ($balance < 0) {
            $balance = 0;
        }

        if (empty($name)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            try {
                if (!empty($phone)) {
                    $existing = $db->queryOne("SELECT id FROM customers WHERE phone = ?", [$phone]);
                    if ($existing) {
                        throw new InvalidArgumentException('رقم الهاتف موجود بالفعل');
                    }
                }

                $result = $db->execute(
                    "INSERT INTO customers (name, phone, balance, address, status, created_by) 
                     VALUES (?, ?, ?, ?, 'active', ?)",
                    [$name, $phone ?: null, $balance, $address ?: null, $currentUser['id']]
                );

                logAudit($currentUser['id'], 'add_customer', 'customer', $result['insert_id'], null, [
                    'name' => $name
                ]);

                $success = 'تم إضافة العميل بنجاح';
            } catch (InvalidArgumentException $userError) {
                $error = $userError->getMessage();
            } catch (Throwable $addCustomerError) {
                error_log('Add customer error: ' . $addCustomerError->getMessage());
                $error = 'حدث خطأ أثناء إضافة العميل. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

try {
    $createdByColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
    if (empty($createdByColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN created_by INT NULL AFTER status");
        $db->execute("ALTER TABLE customers ADD CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
    }
} catch (Throwable $migrationError) {
    error_log('Customers created_by migration error: ' . $migrationError->getMessage());
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? 'active';

// بناء استعلام SQL
$sql = "SELECT c.*, u.full_name as created_by_name
        FROM customers c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM customers WHERE 1=1";
$params = [];
$countParams = [];

if ($status === 'active') {
    $sql .= " AND c.status = 'active'";
    $countSql .= " AND status = 'active'";
}

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCustomers = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCustomers / $perPage);

$sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$customers = $db->query($sql, $params);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people me-2"></i>العملاء</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
        <i class="bi bi-person-plus me-2"></i>إضافة عميل جديد
    </button>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var collectionModal = document.getElementById('collectPaymentModal');
    if (!collectionModal) {
        return;
    }

    var nameElement = collectionModal.querySelector('.collection-customer-name');
    var debtElement = collectionModal.querySelector('.collection-current-debt');
    var customerIdInput = collectionModal.querySelector('input[name="customer_id"]');
    var amountInput = collectionModal.querySelector('input[name="amount"]');

    if (!nameElement || !debtElement || !customerIdInput || !amountInput) {
        return;
    }

    collectionModal.addEventListener('show.bs.modal', function (event) {
        var triggerButton = event.relatedTarget;
        if (!triggerButton) {
            return;
        }

        var customerName = triggerButton.getAttribute('data-customer-name') || '-';
        var balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
        var balanceFormatted = triggerButton.getAttribute('data-customer-balance-formatted') || balanceRaw;

        nameElement.textContent = customerName;
        debtElement.textContent = balanceFormatted;
        customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';

        amountInput.value = balanceRaw;
        amountInput.setAttribute('max', balanceRaw);
        amountInput.focus();
    });

    collectionModal.addEventListener('hidden.bs.modal', function () {
        nameElement.textContent = '-';
        debtElement.textContent = '-';
        customerIdInput.value = '';
        amountInput.value = '';
        amountInput.removeAttribute('max');
    });
});
</script>

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

<!-- البحث -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="page" value="customers">
            <div class="col-md-8">
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="ابحث بالاسم، رقم الهاتف، أو العنوان...">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>نشط</option>
                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>الكل</option>
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

<!-- قائمة العملاء -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة العملاء (<?php echo $totalCustomers; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>رقم الهاتف</th>
                        <th>ديون العميل</th>
                        <th>العنوان</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">لا توجد عملاء</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                <td><?php echo formatCurrency($customer['balance'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                <td><?php echo formatDate($customer['created_at']); ?></td>
                                <td>
                                    <?php
                                    $customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                                    $isActive = ($customer['status'] ?? '') === 'active';
                                    $formattedBalance = formatCurrency($customerBalance);
                                    $rawBalance = number_format($customerBalance, 2, '.', '');
                                    ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm <?php echo $customerBalance > 0 ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#collectPaymentModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-balance="<?php echo $rawBalance; ?>"
                                            data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                                            <?php echo $customerBalance > 0 ? '' : 'disabled'; ?>
                                        >
                                            <i class="bi bi-cash-coin me-1"></i>تحصيل
                                        </button>
                                        <span class="badge bg-<?php echo $isActive ? 'success' : 'secondary'; ?>">
                                            <?php echo $isActive ? 'نشط' : 'غير نشط'; ?>
                                        </span>
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
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=customers&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=customers&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=customers&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=customers&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=customers&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal تحصيل ديون العميل -->
<div class="modal fade" id="collectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="collect_debt">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="collectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="collectionAmount"
                            name="amount"
                            step="0.01"
                            min="0.01"
                            required
                        >
                        <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إضافة عميل جديد -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عميل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="add_customer">
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
                        <input type="number" class="form-control" name="balance" step="0.01" min="0" value="0">
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

