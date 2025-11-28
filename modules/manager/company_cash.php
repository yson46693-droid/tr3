<?php
/**
 * صفحة خزنة الشركة (المدير)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';

requireRole(['manager', 'accountant']);

$db = db();
$lang = isset($translations) ? $translations : [];

$month = date('n');
$year = date('Y');

$incomeRow = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('income', 'transfer')
");
$expenseRow = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('expense', 'payment')
");
$companyBalance = floatval($incomeRow['total'] ?? 0) - floatval($expenseRow['total'] ?? 0);

$approvedExpensesMonth = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM financial_transactions
    WHERE status = 'approved' AND type IN ('expense','payment')
      AND MONTH(created_at) = ? AND YEAR(created_at) = ?
", [$month, $year]);

$collectionsStatusColumn = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
$collectionsStatusCondition = !empty($collectionsStatusColumn) ? "status IN ('approved','pending')" : '1=1';
$collectionsMonth = $db->queryOne("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM collections
    WHERE $collectionsStatusCondition
      AND MONTH(date) = ? AND YEAR(date) = ?
", [$month, $year]);

$pendingTransactions = $db->queryOne("
    SELECT COUNT(*) AS total
    FROM financial_transactions
    WHERE status = 'pending'
");

$salariesTableExists = $db->queryOne("SHOW TABLES LIKE 'salaries'");
$pendingSalaries = ['total' => 0];
if (!empty($salariesTableExists)) {
    $pendingSalaries = $db->queryOne("
        SELECT COALESCE(SUM(total_amount), 0) AS total
        FROM salaries
        WHERE status IN ('pending', 'approved')
    ");
}

// أحدث التحصيلات
$latestCollections = [];
if ($collectionsStatusColumn !== false) {
    $statusFilter = !empty($collectionsStatusColumn) ? "WHERE c.status IN ('approved','pending')" : '';
    $latestCollections = $db->query("
        SELECT c.*, cust.name AS customer_name, u.full_name AS collector_name
        FROM collections c
        LEFT JOIN customers cust ON c.customer_id = cust.id
        LEFT JOIN users u ON c.collected_by = u.id
        $statusFilter
        ORDER BY c.date DESC, c.created_at DESC
        LIMIT 10
    ");
}

// سجل المعاملات المالية (مختصر)
$transactions = $db->query("
    SELECT ft.*, creator.full_name AS creator_name, creator.username AS creator_username
    FROM financial_transactions ft
    LEFT JOIN users creator ON ft.created_by = creator.id
    ORDER BY ft.created_at DESC
    LIMIT 15
");
?>

<style>
/* Modern Financial Dashboard Styles */
.company-cash-dashboard {
    direction: rtl;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
}

.company-cash-header {
    margin-bottom: 2.5rem;
    padding: 1.5rem 0;
}

.company-cash-header h2 {
    font-size: 2rem;
    font-weight: 700;
    color: #1e3d78;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.company-cash-header h2 i {
    font-size: 2.25rem;
    color: #2b4c80;
    opacity: 0.9;
}

.company-cash-header p {
    color: #64748b;
    font-size: 1rem;
    margin: 0;
}

/* Metric Cards Grid */
.metric-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2.5rem;
}

.metric-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 1.75rem;
    box-shadow: 0 8px 25px rgba(0, 80, 180, 0.08);
    border: none;
    position: relative;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    overflow: hidden;
}

.metric-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 35px rgba(0, 80, 180, 0.12);
}

.metric-card::before {
    content: '';
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, #2b4c80 0%, #1e3d78 100%);
}

.metric-card-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    font-size: 1.5rem;
}

.metric-card-icon.blue {
    background: #e9f1ff;
    color: #2b4c80;
}

.metric-card-icon.red {
    background: #fee2e2;
    color: #dc2626;
}

.metric-card-icon.purple {
    background: #f3e8ff;
    color: #9333ea;
}

.metric-card-title {
    font-size: 0.95rem;
    color: #64748b;
    margin-bottom: 0.75rem;
    font-weight: 500;
}

.metric-card-value {
    font-size: 1.875rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
    line-height: 1.2;
}

.metric-card-description {
    font-size: 0.875rem;
    color: #94a3b8;
    line-height: 1.5;
}

/* Tables Section */
.tables-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2.5rem;
}

.table-card {
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 8px 25px rgba(0, 80, 180, 0.08);
    overflow: hidden;
    border: none;
}

.table-card-header {
    padding: 1.25rem 1.75rem;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.table-card-header h5 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e3d78;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.table-card-header i {
    font-size: 1.25rem;
    color: #2b4c80;
}

.table-card-body {
    padding: 0;
}

.modern-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.modern-table thead th {
    background: #334155;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 1rem 1.25rem;
    text-align: right;
    border: none;
    border-left: 1px solid rgba(255, 255, 255, 0.1);
}

.modern-table thead th:first-child {
    border-left: none;
}

.modern-table tbody td {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    color: #475569;
    font-size: 0.9rem;
}

.modern-table tbody tr:last-child td {
    border-bottom: none;
}

.modern-table tbody tr:hover {
    background: #f8fafc;
}

.modern-table tbody tr:nth-child(even) {
    background: #f8fafc;
}

.modern-table tbody tr:nth-child(even):hover {
    background: #f1f5f9;
}

/* Important Notes Section */
.important-notes-section {
    background: linear-gradient(135deg, #dff0ff 0%, #b9d8ff 100%);
    border-radius: 20px;
    padding: 2rem;
    box-shadow: 0 8px 25px rgba(0, 80, 180, 0.08);
}

.important-notes-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}

.important-notes-header h5 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e3d78;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.important-notes-header i {
    width: 40px;
    height: 40px;
    background: rgba(30, 61, 120, 0.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #1e3d78;
    font-size: 1.1rem;
}

.notes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.25rem;
}

.note-item {
    background: rgba(255, 255, 255, 0.9);
    border-radius: 12px;
    padding: 1.25rem;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.5);
}

.note-item strong {
    display: block;
    color: #1e3d78;
    font-size: 0.95rem;
    margin-bottom: 0.75rem;
    font-weight: 600;
}

.note-item .note-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e293b;
    margin: 0.5rem 0;
}

.note-item small {
    display: block;
    color: #64748b;
    font-size: 0.85rem;
    margin-top: 0.5rem;
    line-height: 1.5;
}

/* Responsive Design */
@media (max-width: 768px) {
    .metric-cards-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .tables-section {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .notes-grid {
        grid-template-columns: 1fr;
    }
    
    .company-cash-header h2 {
        font-size: 1.5rem;
    }
    
    .metric-card {
        padding: 1.5rem;
    }
    
    .table-card-header {
        padding: 1rem 1.25rem;
    }
    
    .modern-table thead th,
    .modern-table tbody td {
        padding: 0.75rem 1rem;
        font-size: 0.85rem;
    }
}

@media (max-width: 480px) {
    .company-cash-header h2 {
        font-size: 1.25rem;
    }
    
    .company-cash-header h2 i {
        font-size: 1.75rem;
    }
    
    .metric-card-value {
        font-size: 1.5rem;
    }
    
    .important-notes-section {
        padding: 1.5rem;
    }
}
</style>

<div class="company-cash-dashboard">
    <!-- Header Section -->
    <div class="company-cash-header">
        <h2>
            <i class="bi bi-building"></i>
            خزنة الشركة
        </h2>
        <p>رؤية شاملة للتدفقات النقدية والالتزامات المالية.</p>
    </div>

    <!-- Metric Cards Grid -->
    <div class="metric-cards-grid">
        <!-- Salaries Pending Disbursement -->
        <div class="metric-card">
            <div class="metric-card-icon purple">
                <i class="bi bi-clipboard-data"></i>
            </div>
            <div class="metric-card-title">رواتب بانتظار الصرف</div>
            <div class="metric-card-value"><?php echo formatCurrency($pendingSalaries['total'] ?? 0); ?></div>
            <div class="metric-card-description">حالات معتمدة/معلقة</div>
        </div>

        <!-- Monthly Expenses -->
        <div class="metric-card">
            <div class="metric-card-icon red">
                <i class="bi bi-arrow-down-circle"></i>
            </div>
            <div class="metric-card-title">مصروفات الشهر</div>
            <div class="metric-card-value"><?php echo formatCurrency($approvedExpensesMonth['total'] ?? 0); ?></div>
            <div class="metric-card-description">معاملات معتمدة فقط</div>
        </div>

        <!-- Monthly Collections -->
        <div class="metric-card">
            <div class="metric-card-icon blue">
                <i class="bi bi-graph-up"></i>
            </div>
            <div class="metric-card-title">تحصيلات الشهر</div>
            <div class="metric-card-value"><?php echo formatCurrency($collectionsMonth['total'] ?? 0); ?></div>
            <div class="metric-card-description">يشمل التحصيلات المعتمدة والمعلقة</div>
        </div>

        <!-- Current Company Balance -->
        <div class="metric-card">
            <div class="metric-card-icon blue">
                <i class="bi bi-wallet2"></i>
            </div>
            <div class="metric-card-title">رصيد الشركة الحالي</div>
            <div class="metric-card-value"><?php echo formatCurrency($companyBalance); ?></div>
            <div class="metric-card-description">إجمالي الإيرادات - المصروفات</div>
        </div>
    </div>

    <!-- Tables Section -->
    <div class="tables-section">
        <!-- Latest Collections from Customers -->
        <div class="table-card">
            <div class="table-card-header">
                <h5>
                    <i class="bi bi-people"></i>
                    آخر التحصيلات من العملاء
                </h5>
            </div>
            <div class="table-card-body">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>المحصل</th>
                                <th>المبلغ</th>
                                <th>العميل</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($latestCollections)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #94a3b8; padding: 2rem;">لا توجد تحصيلات حديثة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($latestCollections as $collection): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($collection['collector_name'] ?? '—'); ?></td>
                                        <td style="color: #10b981; font-weight: 600;"><?php echo formatCurrency($collection['amount']); ?></td>
                                        <td><?php echo htmlspecialchars($collection['customer_name'] ?? 'عميل مجهول'); ?></td>
                                        <td><?php echo htmlspecialchars($collection['date']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Latest Financial Transactions -->
        <div class="table-card">
            <div class="table-card-header">
                <h5>
                    <i class="bi bi-journal-text"></i>
                    أحدث المعاملات المالية
                </h5>
            </div>
            <div class="table-card-body">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>الحالة</th>
                                <th>المبلغ</th>
                                <th>النوع</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #94a3b8; padding: 2rem;">لا توجد معاملات مسجلة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-<?php echo $transaction['status'] === 'approved' ? 'success' : ($transaction['status'] === 'pending' ? 'warning text-dark' : 'danger'); ?>">
                                                <?php echo $transaction['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $isOut = in_array($transaction['type'], ['expense', 'payment'], true);
                                            $amount = formatCurrency($transaction['amount']);
                                            ?>
                                            <span style="color: <?php echo $isOut ? '#dc2626' : '#10b981'; ?>; font-weight: 600;">
                                                <?php echo $isOut ? '-' : '+'; ?><?php echo $amount; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo $transaction['type']; ?></span>
                                            <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars(mb_strimwidth($transaction['description'], 0, 40, '...')); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('Y-m-d', strtotime($transaction['created_at'])); ?>
                                            <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.25rem;">
                                                <?php echo date('H:i', strtotime($transaction['created_at'])); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Important Notes Section -->
    <div class="important-notes-section">
        <div class="important-notes-header">
            <i class="bi bi-exclamation-circle"></i>
            <h5>إشارات هامة</h5>
        </div>
        <div class="notes-grid">
            <div class="note-item">
                <strong>معاملات قيد الاعتماد:</strong>
                <div class="note-value"><?php echo (int)($pendingTransactions['total'] ?? 0); ?></div>
                <small>يرجى مراجعتها لاعتماد الرصيد النهائي.</small>
            </div>
            <div class="note-item">
                <strong>إلتزامات الرواتب:</strong>
                <div class="note-value"><?php echo formatCurrency($pendingSalaries['total'] ?? 0); ?></div>
                <small>مبالغ يجب توفيرها قبل موعد الصرف.</small>
            </div>
            <div class="note-item">
                <strong>تحصيلات الشهر الحالي:</strong>
                <div class="note-value"><?php echo formatCurrency($collectionsMonth['total'] ?? 0); ?></div>
                <small>تساعد في دعم السيولة.</small>
            </div>
        </div>
    </div>
</div>

