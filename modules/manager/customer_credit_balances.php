<?php
/**
 * صفحة العملاء ذوي الرصيد الدائن
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/approval_system.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$db = db();

$success = '';
$error = '';

if (isset($_SESSION['customer_credit_success'])) {
    $success = $_SESSION['customer_credit_success'];
    unset($_SESSION['customer_credit_success']);
}

if (isset($_SESSION['customer_credit_error'])) {
    $error = $_SESSION['customer_credit_error'];
    unset($_SESSION['customer_credit_error']);
}

// معالجة تسوية الرصيد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_credit_balance') {
    $customerId = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $customerType = isset($_POST['customer_type']) ? trim($_POST['customer_type']) : 'rep'; // 'rep' أو 'local'
    $settlementAmount = isset($_POST['settlement_amount']) ? cleanFinancialValue($_POST['settlement_amount']) : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    if ($customerId <= 0) {
        $_SESSION['customer_credit_error'] = 'معرف العميل غير صحيح.';
    } elseif ($settlementAmount <= 0) {
        $_SESSION['customer_credit_error'] = 'يجب إدخال مبلغ تسوية صحيح أكبر من الصفر.';
    } else {
        try {
            // جلب بيانات العميل حسب النوع
            $customer = null;
            $tableName = '';
            if ($customerType === 'local') {
                $tableName = 'local_customers';
                $customer = $db->queryOne(
                    "SELECT id, name, balance, created_by FROM local_customers WHERE id = ?",
                    [$customerId]
                );
            } else {
                $tableName = 'customers';
                $customer = $db->queryOne(
                    "SELECT id, name, balance, rep_id, created_by FROM customers WHERE id = ?",
                    [$customerId]
                );
            }
            
            if (empty($customer)) {
                throw new Exception('العميل غير موجود.');
            }
            
            $currentBalance = (float)($customer['balance'] ?? 0);
            
            // التحقق من أن الرصيد سالب (رصيد دائن)
            if ($currentBalance >= 0) {
                throw new Exception('هذا العميل ليس لديه رصيد دائن.');
            }
            
            $creditAmount = abs($currentBalance);
            
            // التحقق من أن مبلغ التسوية لا يتجاوز الرصيد الدائن
            if ($settlementAmount > $creditAmount) {
                throw new Exception('مبلغ التسوية (' . formatCurrency($settlementAmount) . ') يتجاوز الرصيد الدائن المتاح (' . formatCurrency($creditAmount) . ').');
            }
            
            // التحقق من رصيد خزنة الشركة
            require_once __DIR__ . '/../../includes/approval_system.php';
            $companyBalance = calculateCompanyCashBalance($db);
            
            if ($settlementAmount > $companyBalance) {
                throw new Exception('رصيد خزنة الشركة (' . formatCurrency($companyBalance) . ') غير كافٍ لتسوية الرصيد الدائن (' . formatCurrency($settlementAmount) . ').');
            }
            
            $db->beginTransaction();
            
            try {
                // حساب الرصيد الجديد بعد التسوية
                $newBalance = round($currentBalance + $settlementAmount, 2);
                
                // تحديث رصيد العميل
                $db->execute(
                    "UPDATE {$tableName} SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );
                
                // إضافة معاملة expense في accountant_transactions (خصم من خزنة الشركة)
                $customerName = htmlspecialchars($customer['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $customerTypeLabel = $customerType === 'local' ? 'عميل محلي' : 'عميل مندوب';
                $description = 'تسوية رصيد دائن ل' . $customerTypeLabel . ': ' . $customerName;
                if ($notes) {
                    $description .= ' - ' . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8');
                }
                $referenceNumber = 'CUST-CREDIT-SETTLE-' . ($customerType === 'local' ? 'LOCAL-' : 'REP-') . $customerId . '-' . date('YmdHis');
                
                // التأكد من وجود جدول accountant_transactions
                $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
                if (!empty($accountantTableCheck)) {
                    $db->execute(
                        "INSERT INTO accountant_transactions 
                            (transaction_type, amount, description, reference_number, 
                             status, approved_by, created_by, approved_at, notes)
                         VALUES (?, ?, ?, ?, 'approved', ?, ?, NOW(), ?)",
                        [
                            'expense',
                            $settlementAmount,
                            $description,
                            $referenceNumber,
                            $currentUser['id'],
                            $currentUser['id'],
                            $notes ?: null
                        ]
                    );
                }
                
                // تسجيل في audit_logs
                logAudit(
                    $currentUser['id'],
                    'settle_customer_credit',
                    $customerType === 'local' ? 'local_customer' : 'customer',
                    $customerId,
                    null,
                    [
                        'customer_name' => $customerName,
                        'customer_type' => $customerType,
                        'settlement_amount' => $settlementAmount,
                        'old_balance' => $currentBalance,
                        'new_balance' => $newBalance,
                        'reference_number' => $referenceNumber
                    ]
                );
                
                $db->commit();
                
                $_SESSION['customer_credit_success'] = 'تم تسوية رصيد ' . $customerTypeLabel . ' ' . $customerName . ' بمبلغ ' . formatCurrency($settlementAmount) . ' بنجاح.';
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('Settle customer credit error: ' . $e->getMessage());
            $_SESSION['customer_credit_error'] = 'حدث خطأ أثناء تسوية الرصيد: ' . $e->getMessage();
        }
    }
    
    $redirectTarget = $_SERVER['REQUEST_URI'] ?? '';
    if (!headers_sent()) {
        header('Location: ' . $redirectTarget);
    } else {
        echo '<script>window.location.href = ' . json_encode($redirectTarget) . ';</script>';
    }
    exit;
}

// جلب العملاء ذوي الرصيد الدائن (رصيد سالب) - عملاء المندوبين
$repCreditorCustomers = [];
try {
    $repCreditorCustomers = $db->query(
        "SELECT 
            c.id,
            c.name,
            c.phone,
            c.address,
            c.balance,
            c.created_at,
            u.full_name AS rep_name,
            u.id AS rep_id,
            'rep' AS customer_type
        FROM customers c
        LEFT JOIN users u ON (c.rep_id = u.id OR c.created_by = u.id)
        WHERE (c.rep_id IN (SELECT id FROM users WHERE role = 'sales') 
               OR c.created_by IN (SELECT id FROM users WHERE role = 'sales'))
          AND c.balance < 0
        ORDER BY ABS(c.balance) DESC, c.name ASC"
    );
} catch (Throwable $creditorError) {
    error_log('Rep creditor customers query error: ' . $creditorError->getMessage());
    $repCreditorCustomers = [];
}

// جلب العملاء المحليين ذوي الرصيد الدائن
$localCreditorCustomers = [];
try {
    $localCustomersTableExists = $db->queryOne("SHOW TABLES LIKE 'local_customers'");
    if (!empty($localCustomersTableExists)) {
        $localCreditorCustomers = $db->query(
            "SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at,
                NULL AS rep_name,
                NULL AS rep_id,
                'local' AS customer_type
            FROM local_customers c
            WHERE c.balance < 0
            ORDER BY ABS(c.balance) DESC, c.name ASC"
        );
    }
} catch (Throwable $localCreditorError) {
    error_log('Local creditor customers query error: ' . $localCreditorError->getMessage());
    $localCreditorCustomers = [];
}

// دمج العملاء مع إضافة نوع العميل
$creditorCustomers = [];
foreach ($repCreditorCustomers as $customer) {
    $customer['customer_type'] = 'rep';
    $creditorCustomers[] = $customer;
}
foreach ($localCreditorCustomers as $customer) {
    $customer['customer_type'] = 'local';
    $creditorCustomers[] = $customer;
}

// ترتيب حسب الرصيد الدائن
usort($creditorCustomers, function($a, $b) {
    $balanceA = abs((float)($a['balance'] ?? 0));
    $balanceB = abs((float)($b['balance'] ?? 0));
    if ($balanceA == $balanceB) {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    }
    return $balanceB <=> $balanceA;
});

// حساب الإجماليات
$totalCreditBalance = 0.0;
$customerCount = count($creditorCustomers);
foreach ($creditorCustomers as $customer) {
    $balanceValue = (float)($customer['balance'] ?? 0.0);
    $totalCreditBalance += abs($balanceValue);
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<!-- صفحة العملاء ذوي الرصيد الدائن -->
<div class="page-header mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h2><i class="bi bi-wallet2 me-2 text-primary"></i>العملاء ذوو الرصيد الدائن</h2>
        <p class="text-muted mb-0">إجمالي الرصيد الدائن: <strong><?php echo formatCurrency($totalCreditBalance); ?></strong> | عدد العملاء: <strong><?php echo number_format($customerCount); ?></strong></p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (empty($creditorCustomers)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-wallet2 text-muted" style="font-size: 4rem;"></i>
            <h5 class="mt-3 text-muted">لا يوجد عملاء ذوو رصيد دائن حالياً</h5>
            <p class="text-muted">جميع العملاء لديهم رصيد صفر أو رصيد مدين</p>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="bi bi-wallet2 me-2"></i>
                قائمة العملاء ذوي الرصيد الدائن (<?php echo number_format($customerCount); ?>)
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>نوع العميل</th>
                            <th>اسم العميل</th>
                            <th>رقم الهاتف</th>
                            <th>العنوان</th>
                            <th>الرصيد الدائن</th>
                            <th>المندوب</th>
                            <th>تاريخ الإضافة</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($creditorCustomers as $index => $customer): ?>
                            <?php
                            $balanceValue = (float)($customer['balance'] ?? 0.0);
                            $creditAmount = abs($balanceValue);
                            ?>
                            <?php
                            $customerType = $customer['customer_type'] ?? 'rep';
                            $customerTypeLabel = $customerType === 'local' ? 'عميل محلي' : 'عميل مندوب';
                            $customerTypeBadge = $customerType === 'local' ? 'bg-info' : 'bg-secondary';
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <span class="badge <?php echo $customerTypeBadge; ?>">
                                        <?php echo htmlspecialchars($customerTypeLabel); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary fs-6 px-3 py-2">
                                        <?php echo formatCurrency($creditAmount); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($customerType === 'rep' && !empty($customer['rep_name'])): ?>
                                        <span class="text-muted">
                                            <?php echo htmlspecialchars($customer['rep_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo function_exists('formatDate') 
                                        ? formatDate($customer['created_at'] ?? '') 
                                        : htmlspecialchars((string)($customer['created_at'] ?? '-')); ?>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="btn btn-sm btn-success" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#settleCreditModal<?php echo $customerType; ?>_<?php echo $customer['id']; ?>"
                                            title="تسوية الرصيد الدائن">
                                        <i class="bi bi-cash-coin me-1"></i>تسوية الرصيد
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Modal تسوية الرصيد -->
                            <div class="modal fade" id="settleCreditModal<?php echo $customerType; ?>_<?php echo $customer['id']; ?>" tabindex="-1" aria-labelledby="settleCreditModalLabel<?php echo $customerType; ?>_<?php echo $customer['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="settleCreditModalLabel<?php echo $customerType; ?>_<?php echo $customer['id']; ?>">
                                                <i class="bi bi-cash-coin me-2"></i>تسوية رصيد دائن
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form method="POST" id="settleCreditForm<?php echo $customerType; ?>_<?php echo $customer['id']; ?>">
                                            <input type="hidden" name="action" value="settle_credit_balance">
                                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                                            <input type="hidden" name="customer_type" value="<?php echo htmlspecialchars($customerType); ?>">
                                            <div class="modal-body">
                                                <div class="mb-3">
                                                    <label class="form-label">اسم العميل</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($customer['name'] ?? ''); ?>" readonly style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">الرصيد الدائن الحالي</label>
                                                    <input type="text" class="form-control" value="<?php echo formatCurrency($creditAmount); ?>" readonly style="background-color: #f8f9fa; font-weight: bold; color: #0d6efd;">
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">نوع العميل</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($customerTypeLabel); ?>" readonly style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="mb-3">
                                                    <label for="settlementAmount<?php echo $customerType; ?>_<?php echo $customer['id']; ?>" class="form-label">
                                                        مبلغ التسوية <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">ج.م</span>
                                                        <input type="number" 
                                                               step="0.01" 
                                                               min="0.01" 
                                                               max="<?php echo $creditAmount; ?>"
                                                               class="form-control" 
                                                               id="settlementAmount<?php echo $customerType; ?>_<?php echo $customer['id']; ?>" 
                                                               name="settlement_amount" 
                                                               required 
                                                               value="<?php echo $creditAmount; ?>"
                                                               placeholder="أدخل مبلغ التسوية">
                                                    </div>
                                                    <small class="text-muted">الحد الأقصى: <?php echo formatCurrency($creditAmount); ?></small>
                                                </div>
                                                <div class="mb-3">
                                                    <label for="settlementNotes<?php echo $customerType; ?>_<?php echo $customer['id']; ?>" class="form-label">ملاحظات (اختياري)</label>
                                                    <textarea class="form-control" 
                                                              id="settlementNotes<?php echo $customerType; ?>_<?php echo $customer['id']; ?>" 
                                                              name="notes" 
                                                              rows="3" 
                                                              placeholder="أدخل أي ملاحظات إضافية..."></textarea>
                                                </div>
                                                <div class="alert alert-info">
                                                    <i class="bi bi-info-circle me-2"></i>
                                                    <strong>ملاحظة:</strong> سيتم خصم مبلغ التسوية من خزنة الشركة.
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-check-circle me-1"></i>تأكيد التسوية
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // التحقق من مبلغ التسوية قبل الإرسال
    const settleForms = document.querySelectorAll('[id^="settleCreditForm"]');
    settleForms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const formId = form.id;
            // استخراج المعرف من formId (مثل: settleCreditFormrep_123 أو settleCreditFormlocal_456)
            const parts = formId.replace('settleCreditForm', '').split('_');
            const customerId = parts.length > 1 ? parts[1] : parts[0];
            const customerType = parts.length > 1 ? parts[0] : 'rep';
            const amountInputId = 'settlementAmount' + customerType + '_' + customerId;
            const amountInput = document.getElementById(amountInputId);
            
            if (!amountInput) {
                console.error('Amount input not found:', amountInputId);
                return;
            }
            
            const maxAmount = parseFloat(amountInput.getAttribute('max'));
            const amount = parseFloat(amountInput.value);
            
            if (amount <= 0) {
                e.preventDefault();
                alert('يجب إدخال مبلغ صحيح أكبر من الصفر.');
                amountInput.focus();
                return false;
            }
            
            if (amount > maxAmount) {
                e.preventDefault();
                alert('مبلغ التسوية (' + amount.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م) يتجاوز الرصيد الدائن المتاح (' + maxAmount.toLocaleString('ar-EG', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ج.م).');
                amountInput.focus();
                return false;
            }
            
            // تأكيد قبل الإرسال
            if (!confirm('هل أنت متأكد من تسوية الرصيد الدائن؟ سيتم خصم المبلغ من خزنة الشركة.')) {
                e.preventDefault();
                return false;
            }
        });
    });
});
</script>
