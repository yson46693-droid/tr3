<?php

declare(strict_types=1);

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/components/customers/section_header.php';
require_once __DIR__ . '/../../includes/components/customers/rep_card.php';
require_once __DIR__ . '/../sales/table_styles.php';

requireRole(['manager', 'accountant']);

$currentUser = getCurrentUser();
$currentRole = strtolower((string)($currentUser['role'] ?? 'manager'));
$db = db();
$error = '';
$success = '';

applyPRGPattern($error, $success);

$dashboardScript = basename($_SERVER['PHP_SELF'] ?? 'manager.php');

$representatives = [];
$representativeSummary = [
    'total' => 0,
    'customers' => 0,
    'debtors' => 0,
    'debt' => 0.0,
    'total_collections' => 0.0,
    'total_returns' => 0.0,
    'creditors' => 0,
    'total_credit' => 0.0,
];

try {
    // التحقق من وجود عمود status في جدول collections
    $collectionsStatusCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasCollectionsStatus = !empty($collectionsStatusCheck);
    
    // التحقق من وجود جدول returns
    $returnsTableCheck = $db->queryOne("SHOW TABLES LIKE 'returns'");
    $hasReturnsTable = !empty($returnsTableCheck);
    
    // التحقق من وجود الأعمدة في جدول users
    $hasLastLoginAt = false;
    $hasProfileImage = false;
    $hasProfilePhoto = false;
    try {
        $lastLoginCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'last_login_at'");
        $hasLastLoginAt = !empty($lastLoginCheck);
        $profileImageCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_image'");
        $hasProfileImage = !empty($profileImageCheck);
        $profilePhotoCheck = $db->queryOne("SHOW COLUMNS FROM users LIKE 'profile_photo'");
        $hasProfilePhoto = !empty($profilePhotoCheck);
    } catch (Throwable $e) {
        error_log('Column check error: ' . $e->getMessage());
    }
    
    // بناء SELECT بشكل ديناميكي بناءً على الأعمدة الموجودة
    $selectColumns = [
        'u.id',
        'u.full_name',
        'u.username',
        'u.phone',
        'u.email',
        'u.status'
    ];
    
    if ($hasLastLoginAt) {
        $selectColumns[] = 'u.last_login_at';
    } else {
        $selectColumns[] = 'NULL AS last_login_at';
    }
    
    if ($hasProfileImage) {
        $selectColumns[] = 'u.profile_image';
    } elseif ($hasProfilePhoto) {
        $selectColumns[] = 'u.profile_photo AS profile_image';
    } else {
        $selectColumns[] = 'NULL AS profile_image';
    }
    
    $selectSql = implode(', ', $selectColumns);
    
    // استعلام المندوبين - استخدام استعلام أبسط أولاً ثم حساب الإحصائيات
    $representatives = $db->query(
        "SELECT {$selectSql}
        FROM users u
        WHERE u.role = 'sales'
        ORDER BY u.full_name ASC"
    );
    
    // إذا لم يتم العثور على مندوبين، جرب بدون فلتر status
    if (empty($representatives)) {
        $selectColumnsAlt = [
            'id',
            'full_name',
            'username',
            'phone',
            'email',
            'status'
        ];
        
        if ($hasLastLoginAt) {
            $selectColumnsAlt[] = 'last_login_at';
        } else {
            $selectColumnsAlt[] = 'NULL AS last_login_at';
        }
        
        if ($hasProfileImage) {
            $selectColumnsAlt[] = 'profile_image';
        } elseif ($hasProfilePhoto) {
            $selectColumnsAlt[] = 'profile_photo AS profile_image';
        } else {
            $selectColumnsAlt[] = 'NULL AS profile_image';
        }
        
        $selectSqlAlt = implode(', ', $selectColumnsAlt);
        
        $representatives = $db->query(
            "SELECT {$selectSqlAlt}
            FROM users
            WHERE role = 'sales' OR role LIKE '%sales%'
            ORDER BY full_name ASC"
        );
    }
    
    // حساب إحصائيات العملاء لكل مندوب
    foreach ($representatives as &$rep) {
        $repId = (int)($rep['id'] ?? 0);
        if ($repId > 0) {
            try {
                // استخدام استعلام بسيط وواضح
                $customerStats = $db->queryOne(
                    "SELECT 
                        COUNT(*) AS customer_count,
                        COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt,
                        COALESCE(SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END), 0) AS debtor_count,
                        COALESCE(SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END), 0) AS total_credit,
                        COALESCE(SUM(CASE WHEN balance < 0 THEN 1 ELSE 0 END), 0) AS creditor_count
                    FROM customers
                    WHERE rep_id = ? OR created_by = ?",
                    [$repId, $repId]
                );
                
                if ($customerStats !== null && is_array($customerStats)) {
                    $rep['customer_count'] = (int)($customerStats['customer_count'] ?? 0);
                    $rep['total_debt'] = (float)($customerStats['total_debt'] ?? 0.0);
                    $rep['debtor_count'] = (int)($customerStats['debtor_count'] ?? 0);
                    $rep['total_credit'] = (float)($customerStats['total_credit'] ?? 0.0);
                    $rep['creditor_count'] = (int)($customerStats['creditor_count'] ?? 0);
                } else {
                    $rep['customer_count'] = 0;
                    $rep['total_debt'] = 0.0;
                    $rep['debtor_count'] = 0;
                    $rep['total_credit'] = 0.0;
                    $rep['creditor_count'] = 0;
                }
            } catch (Throwable $statsError) {
                error_log('Customer stats error for rep ' . $repId . ': ' . $statsError->getMessage());
                $rep['customer_count'] = 0;
                $rep['total_debt'] = 0.0;
                $rep['debtor_count'] = 0;
            }
        } else {
            $rep['customer_count'] = 0;
            $rep['total_debt'] = 0.0;
            $rep['debtor_count'] = 0;
        }
    }
    unset($rep);
    
    // ترتيب المندوبين حسب عدد العملاء
    usort($representatives, function($a, $b) {
        $countA = (int)($a['customer_count'] ?? 0);
        $countB = (int)($b['customer_count'] ?? 0);
        if ($countA === $countB) {
            return strcmp($a['full_name'] ?? '', $b['full_name'] ?? '');
        }
        return $countB - $countA;
    });

    // حساب إجمالي التحصيلات والمرتجعات لكل مندوب
    foreach ($representatives as &$repRow) {
        $repId = (int)($repRow['id'] ?? 0);
        
        // حساب إجمالي التحصيلات للمندوب
        $collectionsTotal = 0.0;
        try {
            $collectionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'collections'");
            if (!empty($collectionsTableCheck)) {
                if ($hasCollectionsStatus) {
                    $collectionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) AS total_collections
                         FROM collections
                         WHERE collected_by = ? AND status IN ('pending', 'approved')",
                        [$repId]
                    );
                } else {
                    $collectionsResult = $db->queryOne(
                        "SELECT COALESCE(SUM(amount), 0) AS total_collections
                         FROM collections
                         WHERE collected_by = ?",
                        [$repId]
                    );
                }
                $collectionsTotal = (float)($collectionsResult['total_collections'] ?? 0.0);
            }
        } catch (Throwable $collectionsError) {
            error_log('Collections calculation error for rep ' . $repId . ': ' . $collectionsError->getMessage());
        }
        
        // حساب إجمالي المرتجعات للمندوب
        $returnsTotal = 0.0;
        if ($hasReturnsTable) {
            try {
                $returnsResult = $db->queryOne(
                    "SELECT COALESCE(SUM(refund_amount), 0) AS total_returns
                     FROM returns
                     WHERE sales_rep_id = ? AND status IN ('approved', 'processed', 'completed')",
                    [$repId]
                );
                $returnsTotal = (float)($returnsResult['total_returns'] ?? 0.0);
            } catch (Throwable $returnsError) {
                error_log('Returns calculation error for rep ' . $repId . ': ' . $returnsError->getMessage());
            }
        }
        
        $repRow['total_collections'] = $collectionsTotal;
        $repRow['total_returns'] = $returnsTotal;
        
        // تحديث الإحصائيات الإجمالية
        $representativeSummary['total']++;
        $representativeSummary['customers'] += (int)($repRow['customer_count'] ?? 0);
        $representativeSummary['debtors'] += (int)($repRow['debtor_count'] ?? 0);
        $representativeSummary['debt'] += (float)($repRow['total_debt'] ?? 0.0);
        $representativeSummary['creditors'] += (int)($repRow['creditor_count'] ?? 0);
        $representativeSummary['total_credit'] += (float)($repRow['total_credit'] ?? 0.0);
        $representativeSummary['total_collections'] += $collectionsTotal;
        $representativeSummary['total_returns'] += $returnsTotal;
    }
    unset($repRow);
    
} catch (Throwable $repsError) {
    error_log('Manager representatives list error: ' . $repsError->getMessage());
    error_log('Stack trace: ' . $repsError->getTraceAsString());
    $representatives = [];
    
    // محاولة استعلام بسيط للتحقق من وجود مندوبين
    try {
        $simpleTest = $db->query("SELECT id, full_name, username, role FROM users WHERE role = 'sales' LIMIT 10");
        error_log('Simple test query found ' . count($simpleTest) . ' sales reps');
        if (!empty($simpleTest)) {
            // إعادة بناء القائمة بشكل بسيط
            $representatives = [];
            foreach ($simpleTest as $rep) {
                $representatives[] = [
                    'id' => (int)($rep['id'] ?? 0),
                    'full_name' => $rep['full_name'] ?? $rep['username'] ?? '',
                    'username' => $rep['username'] ?? '',
                    'phone' => '',
                    'email' => '',
                    'status' => 'active',
                    'last_login_at' => null,
                    'profile_image' => null,
                    'customer_count' => 0,
                    'total_debt' => 0.0,
                    'debtor_count' => 0,
                    'total_collections' => 0.0,
                    'total_returns' => 0.0,
                ];
            }
        }
    } catch (Throwable $testError) {
        error_log('Simple test query also failed: ' . $testError->getMessage());
    }
}

renderCustomersSectionHeader([
    'title' => 'عملاء المندوبين',
    'active_tab' => null,
    'tabs' => [],
    'primary_btn' => null,
]);

if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">عدد المناديب</div>
                <div class="fs-4 fw-bold mb-0"><?php echo number_format($representativeSummary['total']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">إجمالي العملاء</div>
                <div class="fs-4 fw-bold mb-0"><?php echo number_format($representativeSummary['customers']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">العملاء المدينون</div>
                <div class="fs-4 fw-bold text-warning mb-0"><?php echo number_format($representativeSummary['debtors']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">إجمالي الديون</div>
                <div class="fs-4 fw-bold text-danger mb-0"><?php echo formatCurrency($representativeSummary['debt']); ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">إجمالي التحصيلات</div>
                    <div class="fs-4 fw-bold text-success mb-0"><?php echo formatCurrency($representativeSummary['total_collections']); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-cash-coin"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">إجمالي المرتجعات</div>
                    <div class="fs-4 fw-bold text-info mb-0"><?php echo formatCurrency($representativeSummary['total_returns']); ?></div>
                </div>
                <span class="text-info display-6"><i class="bi bi-arrow-left-right"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">العملاء الدائنين</div>
                    <div class="fs-4 fw-bold text-primary mb-0"><?php echo number_format($representativeSummary['creditors']); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-people"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">إجمالي الرصيد الدائن</div>
                    <div class="fs-4 fw-bold text-primary mb-0"><?php echo formatCurrency($representativeSummary['total_credit']); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
</div>

<?php
// بناء الرابط بشكل صحيح
$baseUrl = getRelativeUrl($dashboardScript);
$viewBaseUrl = $baseUrl . (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=rep_customers_view';
renderRepresentativeCards($representatives, [
    'view_base_url' => $viewBaseUrl,
]);
?>

<?php
// جلب العملاء ذوي الرصيد الدائن (رصيد سالب)
$creditorCustomers = [];
try {
    $creditorCustomers = $db->query(
        "SELECT 
            c.id,
            c.name,
            c.phone,
            c.email,
            c.address,
            c.balance,
            c.created_at,
            u.full_name AS rep_name,
            u.id AS rep_id
        FROM customers c
        LEFT JOIN users u ON (c.rep_id = u.id OR c.created_by = u.id)
        WHERE (c.rep_id IN (SELECT id FROM users WHERE role = 'sales') 
               OR c.created_by IN (SELECT id FROM users WHERE role = 'sales'))
          AND c.balance < 0
        ORDER BY ABS(c.balance) DESC, c.name ASC"
    );
} catch (Throwable $creditorError) {
    error_log('Creditor customers query error: ' . $creditorError->getMessage());
    $creditorCustomers = [];
}
?>

<?php if (!empty($creditorCustomers)): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-wallet2 me-2"></i>
                    العملاء ذوو الرصيد الدائن (<?php echo number_format(count($creditorCustomers)); ?>)
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>اسم العميل</th>
                                <th>رقم الهاتف</th>
                                <th>البريد الإلكتروني</th>
                                <th>العنوان</th>
                                <th>الرصيد الدائن</th>
                                <th>المندوب</th>
                                <th>تاريخ الإضافة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($creditorCustomers as $customer): ?>
                                <?php
                                $balanceValue = (float)($customer['balance'] ?? 0.0);
                                $creditAmount = abs($balanceValue);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-primary-subtle text-primary fs-6">
                                            <?php echo formatCurrency($creditAmount); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($customer['rep_name'])): ?>
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
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.representative-card-link {
    display: block;
    cursor: pointer;
}
.representative-card {
    border-radius: 18px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    cursor: pointer;
}
.representative-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}
.rep-stat-card {
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    padding: 0.75rem;
    background: #fff;
}
.rep-stat-card.border-success {
    border-color: rgba(25, 135, 84, 0.3) !important;
    background: rgba(25, 135, 84, 0.05);
}
.rep-stat-card.border-info {
    border-color: rgba(13, 202, 240, 0.3) !important;
    background: rgba(13, 202, 240, 0.05);
}
.rep-stat-value {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<!-- Modal تفاصيل المندوب -->
<div class="modal fade" id="repDetailsModal" tabindex="-1" aria-labelledby="repDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="repDetailsModalLabel">
                    <i class="bi bi-person-circle me-2"></i>
                    <span id="repModalName">تفاصيل المندوب</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div id="repDetailsLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل البيانات...</p>
                </div>
                <div id="repDetailsContent" style="display: none;">
                    <!-- الإحصائيات -->
                    <div class="row g-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">عدد العملاء</div>
                                    <div class="h4 mb-0 text-primary" id="repCustomerCount">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">إجمالي الديون</div>
                                    <div class="h4 mb-0 text-danger" id="repTotalDebt">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">إجمالي التحصيلات</div>
                                    <div class="h4 mb-0 text-success" id="repTotalCollections">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body text-center">
                                    <div class="text-muted small mb-2">إجمالي المرتجعات</div>
                                    <div class="h4 mb-0 text-info" id="repTotalReturns">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- قائمة العملاء -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-people me-2"></i>قائمة العملاء</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>اسم العميل</th>
                                        <th>الهاتف</th>
                                        <th>الرصيد</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="repCustomersList">
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">لا توجد بيانات</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- قائمة التحصيلات -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-cash-coin me-2"></i>التحصيلات</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>العميل</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody id="repCollectionsList">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد تحصيلات</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- قائمة المرتجعات -->
                    <div>
                        <h6 class="mb-3"><i class="bi bi-arrow-left-right me-2"></i>المرتجعات</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>التاريخ</th>
                                        <th>العميل</th>
                                        <th>المبلغ</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody id="repReturnsList">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد مرتجعات</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
function loadRepDetails(repId, repName) {
    // تحديث اسم المندوب في الـ modal
    const modalNameEl = document.getElementById('repModalName');
    if (modalNameEl) {
        modalNameEl.textContent = 'تفاصيل: ' + repName;
    }
    
    // إظهار loading وإخفاء المحتوى
    const loadingEl = document.getElementById('repDetailsLoading');
    const contentEl = document.getElementById('repDetailsContent');
    if (loadingEl) loadingEl.style.display = 'block';
    if (contentEl) contentEl.style.display = 'none';
    
    // تعريف baseUrl لاستخدامه في الدالة
    const baseUrl = '<?php echo getRelativeUrl($dashboardScript); ?>';
    
    // تحديث رابط عرض جميع العملاء
    const viewCustomersLink = document.getElementById('repViewCustomersLink');
    if (viewCustomersLink) {
        viewCustomersLink.href = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=rep_customers_view&rep_id=' + repId;
    }
    
    // جلب البيانات من API
    const apiUrl = '<?php echo getRelativeUrl("api/get_rep_details.php"); ?>';
    fetch(apiUrl + '?rep_id=' + repId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // تحديث الإحصائيات
                document.getElementById('repCustomerCount').textContent = data.stats.customer_count || 0;
                document.getElementById('repTotalDebt').textContent = formatCurrency(data.stats.total_debt || 0);
                document.getElementById('repTotalCollections').textContent = formatCurrency(data.stats.total_collections || 0);
                document.getElementById('repTotalReturns').textContent = formatCurrency(data.stats.total_returns || 0);
                
                // تحديث قائمة العملاء
                const customersList = document.getElementById('repCustomersList');
                if (data.customers && data.customers.length > 0) {
                    customersList.innerHTML = data.customers.map(customer => `
                        <tr>
                            <td>${escapeHtml(customer.name || '—')}</td>
                            <td>${escapeHtml(customer.phone || '—')}</td>
                            <td class="${customer.balance > 0 ? 'text-danger' : customer.balance < 0 ? 'text-success' : ''}">
                                ${formatCurrency(customer.balance || 0)}
                            </td>
                            <td>
                                <span class="badge ${customer.status === 'active' ? 'bg-success' : 'bg-secondary'}">
                                    ${customer.status === 'active' ? 'نشط' : 'غير نشط'}
                                </span>
                            </td>
                            <td>
                                <a href="${baseUrl}${baseUrl.includes('?') ? '&' : '?'}page=rep_customers_view&rep_id=${repId}&customer_id=${customer.id}" 
                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    customersList.innerHTML = '<tr><td colspan="5" class="text-center text-muted">لا يوجد عملاء</td></tr>';
                }
                
                // تحديث قائمة التحصيلات
                const collectionsList = document.getElementById('repCollectionsList');
                if (data.collections && data.collections.length > 0) {
                    collectionsList.innerHTML = data.collections.map(collection => `
                        <tr>
                            <td>${formatDate(collection.date || collection.created_at)}</td>
                            <td>${escapeHtml(collection.customer_name || '—')}</td>
                            <td class="text-success">${formatCurrency(collection.amount || 0)}</td>
                            <td>
                                <span class="badge ${collection.status === 'approved' ? 'bg-success' : collection.status === 'pending' ? 'bg-warning' : 'bg-secondary'}">
                                    ${collection.status === 'approved' ? 'معتمد' : collection.status === 'pending' ? 'معلق' : collection.status || '—'}
                                </span>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    collectionsList.innerHTML = '<tr><td colspan="4" class="text-center text-muted">لا توجد تحصيلات</td></tr>';
                }
                
                // تحديث قائمة المرتجعات
                const returnsList = document.getElementById('repReturnsList');
                if (data.returns && data.returns.length > 0) {
                    returnsList.innerHTML = data.returns.map(returnItem => `
                        <tr>
                            <td>${formatDate(returnItem.return_date || returnItem.created_at)}</td>
                            <td>${escapeHtml(returnItem.customer_name || '—')}</td>
                            <td class="text-info">${formatCurrency(returnItem.refund_amount || 0)}</td>
                            <td>
                                <span class="badge ${returnItem.status === 'approved' || returnItem.status === 'completed' ? 'bg-success' : returnItem.status === 'pending' ? 'bg-warning' : 'bg-secondary'}">
                                    ${returnItem.status === 'approved' ? 'معتمد' : returnItem.status === 'completed' ? 'مكتمل' : returnItem.status === 'pending' ? 'معلق' : returnItem.status || '—'}
                                </span>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    returnsList.innerHTML = '<tr><td colspan="4" class="text-center text-muted">لا توجد مرتجعات</td></tr>';
                }
                
                // إخفاء loading وإظهار المحتوى
                if (loadingEl) loadingEl.style.display = 'none';
                if (contentEl) contentEl.style.display = 'block';
            } else {
                document.getElementById('repDetailsLoading').innerHTML = 
                    '<div class="alert alert-danger">فشل تحميل البيانات: ' + (data.error || 'خطأ غير معروف') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading rep details:', error);
            if (loadingEl) {
                loadingEl.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل البيانات</div>';
            }
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('ar-EG', {
        style: 'currency',
        currency: 'EGP',
        minimumFractionDigits: 2
    }).format(amount || 0);
}

function formatDate(dateString) {
    if (!dateString) return '—';
    const date = new Date(dateString);
    return date.toLocaleDateString('ar-EG', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}
</script>

