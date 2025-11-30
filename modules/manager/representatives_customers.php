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
$dashboardScript = basename($_SERVER['PHP_SELF'] ?? 'manager.php');

$error = '';
$success = '';
applyPRGPattern($error, $success);

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
                                        <th>الموقع</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody id="repCustomersList">
                                    <tr>
                                        <td colspan="4" class="text-center text-muted">لا توجد بيانات</td>
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
// متغير لتخزين آخر AbortController لإلغاء الطلبات السابقة
let currentRepDetailsAbortController = null;

function loadRepDetails(repId, repName) {
    // إلغاء أي طلب سابق إذا كان موجوداً
    if (currentRepDetailsAbortController) {
        currentRepDetailsAbortController.abort();
        currentRepDetailsAbortController = null;
    }
    
    // إنشاء AbortController جديد للطلب الحالي
    currentRepDetailsAbortController = new AbortController();
    
    // تحديث اسم المندوب في الـ modal
    const modalNameEl = document.getElementById('repModalName');
    if (modalNameEl) {
        modalNameEl.textContent = 'تفاصيل: ' + repName;
    }
    
    // إظهار loading وإخفاء المحتوى
    const loadingEl = document.getElementById('repDetailsLoading');
    const contentEl = document.getElementById('repDetailsContent');
    if (loadingEl) {
        loadingEl.style.display = 'block';
        loadingEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    }
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
    fetch(apiUrl + '?rep_id=' + repId, {
        signal: currentRepDetailsAbortController.signal,
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
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
                    customersList.innerHTML = data.customers.map(customer => {
                        const balance = parseFloat(customer.balance || 0);
                        const rawBalance = balance.toFixed(2);
                        const formattedBalance = formatCurrency(Math.abs(balance));
                        const balanceClass = balance > 0 ? 'text-danger' : balance < 0 ? 'text-success' : '';
                        const collectDisabled = balance <= 0 ? 'disabled' : '';
                        const collectBtnClass = balance > 0 ? 'btn-success' : 'btn-outline-secondary';
                        
                        // معالجة الموقع
                        const hasLocation = customer.latitude !== null && customer.longitude !== null;
                        const latValue = hasLocation ? parseFloat(customer.latitude) : null;
                        const lngValue = hasLocation ? parseFloat(customer.longitude) : null;
                        const locationButtons = hasLocation ? `
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-info rep-location-view-btn"
                                data-latitude="${latValue.toFixed(8)}"
                                data-longitude="${lngValue.toFixed(8)}"
                                data-customer-name="${escapeHtml(customer.name || '—')}"
                                title="عرض الموقع على الخريطة"
                            >
                                <i class="bi bi-map me-1"></i>عرض
                            </button>
                        ` : `
                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                        `;
                        
                        return `
                        <tr>
                            <td>${escapeHtml(customer.name || '—')}</td>
                            <td>${escapeHtml(customer.phone || '—')}</td>
                            <td class="${balanceClass}">
                                ${formatCurrency(balance || 0)}
                            </td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary rep-location-capture-btn"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                        title="تحديد موقع العميل"
                                    >
                                        <i class="bi bi-geo-alt me-1"></i>تحديد
                                    </button>
                                    ${locationButtons}
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm ${collectBtnClass} rep-collect-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#repCollectPaymentModal"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                        data-customer-balance="${rawBalance}"
                                        data-customer-balance-formatted="${formattedBalance}"
                                        ${collectDisabled}
                                    >
                                        <i class="bi bi-cash-coin me-1"></i>تحصيل
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-dark rep-history-btn js-customer-history"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                    >
                                        <i class="bi bi-journal-text me-1"></i>سجل المشتريات
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary rep-return-btn js-customer-purchase-history"
                                        data-customer-id="${customer.id || 0}"
                                        data-customer-name="${escapeHtml(customer.name || '—')}"
                                        title="سجل مشتريات العميل - إنشاء مرتجع"
                                    >
                                        <i class="bi bi-arrow-return-left me-1"></i>إرجاع
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                    }).join('');
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
            // تجاهل الأخطاء الناتجة عن إلغاء الطلب
            if (error.name === 'AbortError') {
                console.log('Request aborted');
                return;
            }
            
            console.error('Error loading rep details:', error);
            if (loadingEl) {
                loadingEl.innerHTML = '<div class="alert alert-danger">حدث خطأ أثناء تحميل البيانات: ' + (error.message || 'خطأ غير معروف') + '</div>';
            }
        })
        .finally(() => {
            // تنظيف AbortController بعد اكتمال الطلب
            if (currentRepDetailsAbortController) {
                currentRepDetailsAbortController = null;
            }
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    if (typeof amount === 'string') {
        amount = parseFloat(amount) || 0;
    }
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

/**
 * دالة مساعدة لجلب JSON من API مع معالجة أخطاء محسنة
 * @param {string} url - URL للـ API endpoint
 * @returns {Promise<Object>} - البيانات كـ JSON object
 */
function fetchJson(url) {
    return fetch(url, {
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        // قراءة النص أولاً للتحقق من نوع المحتوى
        return response.text().then(text => {
            const contentType = response.headers.get('content-type') || '';
            
            // التحقق من أن الاستجابة JSON
            if (!contentType.includes('application/json')) {
                console.error('Expected JSON but got:', contentType);
                console.error('Response text:', text.substring(0, 1000));
                
                // محاولة التحقق من أن النص هو HTML (صفحة خطأ أو redirect)
                if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                    throw new Error('الخادم يعيد صفحة HTML بدلاً من JSON. قد تكون الجلسة منتهية أو هناك خطأ في الخادم.');
                }
                
                throw new Error('استجابة غير صحيحة من الخادم. يرجى التحقق من أن endpoint يعيد JSON.');
            }
            
            if (!response.ok) {
                console.error('Error response:', text.substring(0, 500));
                
                // محاولة تحليل JSON للخطأ
                try {
                    const errorData = JSON.parse(text);
                    throw new Error(errorData.message || 'تعذر تحميل البيانات. حالة الخادم: ' + response.status);
                } catch (e) {
                    throw new Error('تعذر تحميل البيانات. حالة الخادم: ' + response.status);
                }
            }
            
            // محاولة تحليل JSON
            try {
                return JSON.parse(text);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response text:', text.substring(0, 500));
                throw new Error('فشل تحليل استجابة JSON من الخادم.');
            }
        });
    });
}

// دوال مساعدة لبناء HTML من JSON (استخدام formatCurrency الموجود أعلاه)

function formatCurrencySimple(value) {
    const number = Number(value || 0);
    return number.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ج.م';
}

function renderRepInvoices(rows, tableBody) {
    if (!tableBody) return;
    tableBody.innerHTML = '';
    
    if (!Array.isArray(rows) || rows.length === 0) {
        const emptyRow = document.createElement('tr');
        const emptyCell = document.createElement('td');
        emptyCell.colSpan = 7;
        emptyCell.className = 'text-center text-muted py-4';
        emptyCell.textContent = 'لا توجد فواتير خلال النافذة الزمنية.';
        emptyRow.appendChild(emptyCell);
        tableBody.appendChild(emptyRow);
        return;
    }
    
    rows.forEach(function (row) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${row.invoice_number || '—'}</td>
            <td>${row.invoice_date || '—'}</td>
            <td>${formatCurrencySimple(row.invoice_total || 0)}</td>
            <td>${formatCurrencySimple(row.paid_amount || 0)}</td>
            <td>
                <span class="text-danger fw-semibold">${formatCurrencySimple(row.return_total || 0)}</span>
                <div class="text-muted small">${row.return_count || 0} مرتجع</div>
            </td>
            <td>${formatCurrencySimple(row.net_total || 0)}</td>
            <td>${row.invoice_status || '—'}</td>
        `;
        tableBody.appendChild(tr);
    });
}

function renderRepReturns(list, container) {
    if (!container) return;
    container.innerHTML = '';
    
    if (!Array.isArray(list) || list.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'text-muted';
        empty.textContent = 'لا توجد مرتجعات خلال الفترة.';
        container.appendChild(empty);
        return;
    }
    
    const group = document.createElement('div');
    group.className = 'list-group list-group-flush';
    
    list.forEach(function (item) {
        const row = document.createElement('div');
        row.className = 'list-group-item';
        row.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold">رقم المرتجع: ${item.return_number || '—'}</div>
                    <div class="text-muted small">
                        التاريخ: ${item.return_date || '—'} | النوع: ${item.return_type || '—'}
                    </div>
                </div>
                <div class="text-danger fw-semibold">${formatCurrencySimple(item.refund_amount || 0)}</div>
            </div>
            <div class="text-muted small mt-1">الحالة: ${item.status || '—'}</div>
        `;
        group.appendChild(row);
    });
    
    container.appendChild(group);
}

function displayRepReturnHistory(history, tableBody) {
    if (!tableBody) return;
    tableBody.innerHTML = '';
    
    if (!Array.isArray(history) || history.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">لا توجد مشتريات متاحة للإرجاع</td></tr>';
        return;
    }
    
    history.forEach(function(item) {
        if (!item.can_return) {
            return; // Skip items that can't be returned
        }
        
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <input type="checkbox" class="form-check-input rep-return-item-checkbox" 
                       data-invoice-id="${item.invoice_id}"
                       data-invoice-item-id="${item.invoice_item_id}"
                       data-product-id="${item.product_id}"
                       data-product-name="${escapeHtml(item.product_name || '')}"
                       data-unit-price="${item.unit_price || 0}"
                       data-batch-number-ids='${JSON.stringify(item.batch_number_ids || [])}'
                       data-batch-numbers='${JSON.stringify(item.batch_numbers || [])}'>
            </td>
            <td>${item.invoice_number || '-'}</td>
            <td>${(item.batch_numbers || []).join(', ') || '-'}</td>
            <td>${escapeHtml(item.product_name || '-')}</td>
            <td>${parseFloat(item.quantity || 0).toFixed(2)}</td>
            <td>${parseFloat(item.returned_quantity || 0).toFixed(2)}</td>
            <td><strong>${parseFloat(item.available_to_return || 0).toFixed(2)}</strong></td>
            <td>${parseFloat(item.unit_price || 0).toFixed(2)} ج.م</td>
            <td>${parseFloat(item.total_price || 0).toFixed(2)} ج.م</td>
            <td>${item.purchase_date || '-'}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="viewRepReturnItemDetails(${item.invoice_item_id})">
                    <i class="bi bi-eye"></i>
                </button>
            </td>
        `;
        tableBody.appendChild(row);
    });
}

function viewRepReturnItemDetails(invoiceItemId) {
    // يمكن إضافة وظيفة لعرض تفاصيل العنصر
    console.log('View details for item:', invoiceItemId);
}

    // تهيئة modal تفاصيل المندوب - تنظيف الطلبات عند إغلاق الـ modal
document.addEventListener('DOMContentLoaded', function() {
    const repDetailsModal = document.getElementById('repDetailsModal');
    if (repDetailsModal) {
        repDetailsModal.addEventListener('hidden.bs.modal', function() {
            // إلغاء أي طلبات جارية عند إغلاق الـ modal
            if (currentRepDetailsAbortController) {
                currentRepDetailsAbortController.abort();
                currentRepDetailsAbortController = null;
            }
        });
    }
});

    // تهيئة modal التحصيل
document.addEventListener('DOMContentLoaded', function() {
    const repCollectModal = document.getElementById('repCollectPaymentModal');
    if (repCollectModal) {
        const nameElement = repCollectModal.querySelector('.rep-collection-customer-name');
        const debtElement = repCollectModal.querySelector('.rep-collection-current-debt');
        const customerIdInput = repCollectModal.querySelector('input[name="customer_id"]');
        const amountInput = repCollectModal.querySelector('input[name="amount"]');

        if (nameElement && debtElement && customerIdInput && amountInput) {
            repCollectModal.addEventListener('show.bs.modal', function (event) {
                const triggerButton = event.relatedTarget;
                if (!triggerButton) {
                    return;
                }

                const customerName = triggerButton.getAttribute('data-customer-name') || '-';
                const balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
                const balanceFormatted = triggerButton.getAttribute('data-customer-balance-formatted') || balanceRaw;
                let numericBalance = parseFloat(balanceRaw);
                if (!Number.isFinite(numericBalance)) {
                    numericBalance = 0;
                }
                const debtAmount = numericBalance > 0 ? numericBalance : 0;

                nameElement.textContent = customerName;
                debtElement.textContent = balanceFormatted;
                customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';

                amountInput.value = debtAmount.toFixed(2);
                amountInput.setAttribute('max', debtAmount.toFixed(2));
                amountInput.setAttribute('min', '0');
                amountInput.readOnly = debtAmount <= 0;
                if (debtAmount > 0) {
                    amountInput.focus();
                }
            });

            repCollectModal.addEventListener('hidden.bs.modal', function () {
                if (amountInput) {
                    amountInput.value = '';
                    amountInput.removeAttribute('max');
                    amountInput.removeAttribute('min');
                    amountInput.readOnly = false;
                }
                if (customerIdInput) {
                    customerIdInput.value = '';
                }
            });
        }
    }
    
    // معالجة أزرار التحصيل لمنع propagation
    document.addEventListener('click', function(e) {
        if (e.target.closest('.rep-collect-btn')) {
            const button = e.target.closest('.rep-collect-btn');
            if (button.hasAttribute('disabled')) {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            // السماح للـ Bootstrap modal بالعمل بشكل طبيعي
        }
    });

    // معالجة أزرار سجل المشتريات (delegation للعناصر الديناميكية)
    document.addEventListener('click', function(e) {
        if (e.target.closest('.rep-history-btn.js-customer-history')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-history-btn.js-customer-history');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name') || '-';
            
            const historyModal = document.getElementById('repCustomerHistoryModal');
            if (historyModal) {
                const nameElement = historyModal.querySelector('.rep-history-customer-name');
                const loadingElement = historyModal.querySelector('.rep-history-loading');
                const contentElement = historyModal.querySelector('.rep-history-content');
                const errorElement = historyModal.querySelector('.rep-history-error');
                const invoicesTableBody = historyModal.querySelector('.rep-history-table tbody');
                const returnsContainer = historyModal.querySelector('.rep-history-returns');
                const totalInvoicesEl = historyModal.querySelector('.rep-history-total-invoices');
                const totalInvoicedEl = historyModal.querySelector('.rep-history-total-invoiced');
                const totalReturnsEl = historyModal.querySelector('.rep-history-total-returns');
                const netTotalEl = historyModal.querySelector('.rep-history-net-total');
                
                if (nameElement) nameElement.textContent = customerName;
                if (loadingElement) loadingElement.classList.remove('d-none');
                if (contentElement) contentElement.classList.add('d-none');
                if (errorElement) errorElement.classList.add('d-none');
                if (invoicesTableBody) invoicesTableBody.innerHTML = '';
                if (returnsContainer) returnsContainer.innerHTML = '';
                
                const modalInstance = bootstrap.Modal.getOrCreateInstance(historyModal);
                modalInstance.show();
                
                // جلب بيانات سجل المشتريات من API endpoint
                const basePath = '<?php echo getBasePath(); ?>';
                const historyUrl = basePath + '/api/customer_history_api.php?customer_id=' + encodeURIComponent(customerId);
                
                console.log('Fetching history from:', historyUrl);
                
                fetchJson(historyUrl)
                .then(payload => {
                    if (!payload || !payload.success) {
                        throw new Error(payload?.message || 'فشل تحميل بيانات السجل.');
                    }
                    
                    const history = payload.history || {};
                    const totals = history.totals || {};
                    
                    // تحديث الإحصائيات
                    if (totalInvoicesEl) {
                        totalInvoicesEl.textContent = Number(totals.invoice_count || 0).toLocaleString('ar-EG');
                    }
                    if (totalInvoicedEl) {
                        totalInvoicedEl.textContent = formatCurrencySimple(totals.total_invoiced || 0);
                    }
                    if (totalReturnsEl) {
                        totalReturnsEl.textContent = formatCurrencySimple(totals.total_returns || 0);
                    }
                    if (netTotalEl) {
                        netTotalEl.textContent = formatCurrencySimple(totals.net_total || 0);
                    }
                    
                    // عرض البيانات
                    renderRepInvoices(Array.isArray(history.invoices) ? history.invoices : [], invoicesTableBody);
                    renderRepReturns(Array.isArray(history.returns) ? history.returns : [], returnsContainer);
                    
                    // إخفاء loading وإظهار المحتوى
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (contentElement) contentElement.classList.remove('d-none');
                    if (errorElement) errorElement.classList.add('d-none');
                })
                .catch(error => {
                    console.error('Error loading history:', error);
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل سجل المشتريات';
                        errorElement.classList.remove('d-none');
                    }
                });
            } else {
                // Fallback: فتح في نافذة جديدة
                const baseUrl = '<?php echo getRelativeUrl($dashboardScript); ?>';
                const historyUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=customers&section=company&action=purchase_history&customer_id=' + encodeURIComponent(customerId);
                window.open(historyUrl, '_blank');
            }
        }
        
        if (e.target.closest('.rep-return-btn.js-customer-purchase-history')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-return-btn.js-customer-purchase-history');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name') || '-';
            
            const returnModal = document.getElementById('repCustomerReturnModal');
            if (returnModal) {
                const nameElement = returnModal.querySelector('.rep-return-customer-name');
                const loadingElement = returnModal.querySelector('.rep-return-loading');
                const contentElement = returnModal.querySelector('.rep-return-content');
                const errorElement = returnModal.querySelector('.rep-return-error');
                const tableBody = returnModal.querySelector('.rep-return-table tbody');
                const customerPhoneEl = returnModal.querySelector('.rep-return-customer-phone');
                const customerAddressEl = returnModal.querySelector('.rep-return-customer-address');
                
                if (nameElement) nameElement.textContent = customerName;
                if (loadingElement) loadingElement.classList.remove('d-none');
                if (contentElement) contentElement.classList.add('d-none');
                if (errorElement) errorElement.classList.add('d-none');
                if (tableBody) tableBody.innerHTML = '';
                
                const modalInstance = bootstrap.Modal.getOrCreateInstance(returnModal);
                modalInstance.show();
                
                // جلب بيانات سجل المشتريات للإرجاع من API
                const basePath = '<?php echo getBasePath(); ?>';
                const returnUrl = basePath + '/api/customer_purchase_history.php?action=get_history&customer_id=' + encodeURIComponent(customerId);
                
                fetchJson(returnUrl)
                .then(data => {
                    if (loadingElement) loadingElement.classList.add('d-none');
                    
                    if (data.success) {
                        // تحديث معلومات العميل
                        if (data.customer) {
                            if (customerPhoneEl) customerPhoneEl.textContent = data.customer.phone || '-';
                            if (customerAddressEl) customerAddressEl.textContent = data.customer.address || '-';
                        }
                        
                        // عرض بيانات المشتريات
                        const purchaseHistory = data.purchase_history || [];
                        displayRepReturnHistory(purchaseHistory, tableBody);
                        
                        if (contentElement) {
                            contentElement.classList.remove('d-none');
                        }
                    } else {
                        if (errorElement) {
                            errorElement.textContent = data.message || 'حدث خطأ أثناء تحميل بيانات الإرجاع';
                            errorElement.classList.remove('d-none');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading return data:', error);
                    if (loadingElement) loadingElement.classList.add('d-none');
                    if (errorElement) {
                        errorElement.textContent = error.message || 'حدث خطأ أثناء تحميل بيانات الإرجاع';
                        errorElement.classList.remove('d-none');
                    }
                });
            } else {
                // Fallback: فتح في نافذة جديدة
                const baseUrl = '<?php echo getRelativeUrl($dashboardScript); ?>';
                const returnUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=customers&section=company&action=purchase_history&ajax=purchase_history&customer_id=' + encodeURIComponent(customerId);
                window.open(returnUrl, '_blank');
            }
        }
        
        // معالجة أزرار عرض الموقع
        if (e.target.closest('.rep-location-view-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-location-view-btn');
            const latitude = button.getAttribute('data-latitude');
            const longitude = button.getAttribute('data-longitude');
            const customerName = button.getAttribute('data-customer-name') || button.closest('tr')?.querySelector('td:first-child')?.textContent?.trim() || '-';
            
            if (!latitude || !longitude) {
                alert('لا يوجد موقع مسجل لهذا العميل.');
                return;
            }
            
            const viewLocationModal = document.getElementById('repViewLocationModal');
            if (viewLocationModal) {
                const locationCustomerName = viewLocationModal.querySelector('.rep-location-customer-name');
                const locationMapFrame = viewLocationModal.querySelector('.rep-location-map-frame');
                const locationExternalLink = viewLocationModal.querySelector('.rep-location-open-map');
                
                if (locationCustomerName) {
                    locationCustomerName.textContent = customerName;
                }
                
                if (locationMapFrame) {
                    const embedUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                    locationMapFrame.src = embedUrl;
                }
                
                if (locationExternalLink) {
                    const externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                    locationExternalLink.href = externalUrl;
                }
                
                const modalInstance = bootstrap.Modal.getOrCreateInstance(viewLocationModal);
                modalInstance.show();
            } else {
                // Fallback: فتح في نافذة جديدة إذا لم يوجد modal
                const url = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                window.open(url, '_blank');
            }
        }
        
        // معالجة أزرار تحديد الموقع
        if (e.target.closest('.rep-location-capture-btn')) {
            e.preventDefault();
            e.stopPropagation();
            const button = e.target.closest('.rep-location-capture-btn');
            const customerId = button.getAttribute('data-customer-id');
            const customerName = button.getAttribute('data-customer-name') || '';
            
            if (!customerId) {
                alert('تعذر تحديد العميل.');
                return;
            }
            
            if (!navigator.geolocation) {
                alert('المتصفح الحالي لا يدعم تحديد الموقع الجغرافي.');
                return;
            }
            
            // تعطيل الزر وإظهار loading
            const originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>جارٍ التحديد...';
            
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    const latitude = position.coords.latitude.toFixed(8);
                    const longitude = position.coords.longitude.toFixed(8);
                    const baseUrl = '<?php echo getRelativeUrl($dashboardScript); ?>';
                    const requestUrl = baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'page=customers&section=company';
                    
                    const formData = new URLSearchParams();
                    formData.append('action', 'update_location');
                    formData.append('customer_id', customerId);
                    formData.append('latitude', latitude);
                    formData.append('longitude', longitude);
                    
                    fetch(requestUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: formData.toString()
                    })
                    .then(response => {
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            return response.text().then(text => {
                                throw new Error('استجابة غير صالحة من الخادم');
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            alert('تم تحديد موقع العميل بنجاح.');
                            // إعادة تحميل بيانات المندوب
                            const repId = button.closest('#repDetailsModal')?.dataset?.repId || 
                                         document.querySelector('[data-rep-id]')?.dataset?.repId ||
                                         document.querySelector('.representative-card[data-rep-id]')?.dataset?.repId;
                            if (repId) {
                                const repName = document.getElementById('repModalName')?.textContent?.replace('تفاصيل: ', '') || '';
                                loadRepDetails(repId, repName);
                            }
                        } else {
                            alert(data.message || 'فشل تحديد الموقع. يرجى المحاولة مرة أخرى.');
                        }
                    })
                    .catch(error => {
                        console.error('Error updating location:', error);
                        alert('حدث خطأ أثناء تحديد الموقع. يرجى المحاولة مرة أخرى.');
                    })
                    .finally(() => {
                        button.disabled = false;
                        button.innerHTML = originalHtml;
                    });
                },
                function (error) {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                    if (error.code === error.PERMISSION_DENIED) {
                        alert('لم يتم منح صلاحية الموقع. يرجى تمكينها من إعدادات المتصفح.');
                    } else {
                        alert('تعذر تحديد الموقع. يرجى المحاولة مرة أخرى.');
                    }
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        }
    });
});
</script>

<!-- Modal تحصيل ديون العميل -->
<div class="modal fade" id="repCollectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars(getRelativeUrl($dashboardScript . '?page=customers&section=company')); ?>">
                <input type="hidden" name="action" value="collect_debt">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 rep-collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning rep-collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="repCollectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="repCollectionAmount"
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
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>تحصيل
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal عرض موقع العميل -->
<div class="modal fade" id="repViewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-5 fw-bold rep-location-customer-name">-</div>
                </div>
                <div class="ratio ratio-16x9">
                    <iframe
                        class="rep-location-map-frame border rounded"
                        src=""
                        title="معاينة موقع العميل"
                        allowfullscreen
                        loading="lazy"
                    ></iframe>
                </div>
                <p class="mt-3 text-muted mb-0">
                    يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <a href="#" target="_blank" rel="noopener" class="btn btn-primary rep-location-open-map">
                    <i class="bi bi-map me-1"></i> فتح في الخرائط
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal سجل المشتريات -->
<div class="modal fade" id="repCustomerHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">
                    <i class="bi bi-journal-text me-2"></i>
                    سجل مشتريات العميل - <span class="rep-history-customer-name">-</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="rep-history-loading text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل سجل المشتريات...</p>
                </div>
                <div class="rep-history-error alert alert-danger d-none" role="alert"></div>
                <div class="rep-history-content d-none">
                    <!-- الإحصائيات -->
                    <div class="row g-3 mb-4">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">عدد الفواتير</div>
                                    <div class="fs-4 fw-semibold rep-history-total-invoices">0</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">إجمالي الفواتير</div>
                                    <div class="fs-4 fw-semibold rep-history-total-invoiced">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">إجمالي المرتجعات</div>
                                    <div class="fs-4 fw-semibold text-danger rep-history-total-returns">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-body">
                                    <div class="text-muted small">الصافي</div>
                                    <div class="fs-4 fw-semibold text-primary rep-history-net-total">0.00 ج.م</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- الفواتير -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-receipt me-2"></i>الفواتير</h6>
                        <div class="table-responsive">
                            <table class="table table-hover rep-history-table">
                                <thead>
                                    <tr>
                                        <th>رقم الفاتورة</th>
                                        <th>التاريخ</th>
                                        <th>إجمالي الفاتورة</th>
                                        <th>المدفوع</th>
                                        <th>المرتجعات</th>
                                        <th>الصافي</th>
                                        <th>الحالة</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- المرتجعات -->
                    <div class="mb-4">
                        <h6 class="mb-3"><i class="bi bi-arrow-left me-2"></i>المرتجعات</h6>
                        <div class="rep-history-returns"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal إنشاء مرتجع -->
<div class="modal fade" id="repCustomerReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-arrow-return-left me-2"></i>
                    سجل مشتريات العميل - إنشاء مرتجع - <span class="rep-return-customer-name">-</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <!-- معلومات العميل -->
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-muted small">العميل</div>
                                <div class="fs-5 fw-bold rep-return-customer-name">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">الهاتف</div>
                                <div class="rep-return-customer-phone">-</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">العنوان</div>
                                <div class="rep-return-customer-address">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="rep-return-loading text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل بيانات المشتريات...</p>
                </div>
                <div class="rep-return-error alert alert-danger d-none" role="alert"></div>
                <div class="rep-return-content d-none">
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered rep-return-table">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">
                                        <input type="checkbox" id="repSelectAllItems" onchange="repToggleAllItems()">
                                    </th>
                                    <th>رقم الفاتورة</th>
                                    <th>رقم التشغيلة</th>
                                    <th>اسم المنتج</th>
                                    <th>الكمية المشتراة</th>
                                    <th>الكمية المرتجعة</th>
                                    <th>المتاح للإرجاع</th>
                                    <th>سعر الوحدة</th>
                                    <th>السعر الإجمالي</th>
                                    <th>تاريخ الشراء</th>
                                    <th style="width: 100px;">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" id="repCreateReturnBtn" style="display: none;">
                    <i class="bi bi-arrow-return-left me-1"></i>إنشاء مرتجع
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function repToggleAllItems() {
    const selectAll = document.getElementById('repSelectAllItems');
    const checkboxes = document.querySelectorAll('.rep-return-item-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = selectAll.checked;
    });
}
</script>

<script>
// تنظيف modal عرض الموقع عند الإغلاق
document.addEventListener('DOMContentLoaded', function() {
    const viewLocationModal = document.getElementById('repViewLocationModal');
    if (viewLocationModal) {
        viewLocationModal.addEventListener('hidden.bs.modal', function () {
            const locationMapFrame = viewLocationModal.querySelector('.rep-location-map-frame');
            const locationCustomerName = viewLocationModal.querySelector('.rep-location-customer-name');
            const locationExternalLink = viewLocationModal.querySelector('.rep-location-open-map');
            
            if (locationMapFrame) locationMapFrame.src = '';
            if (locationCustomerName) locationCustomerName.textContent = '-';
            if (locationExternalLink) locationExternalLink.href = '#';
        });
    }
});
</script>

