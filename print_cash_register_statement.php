<?php
/**
 * صفحة طباعة كشف حساب شامل لخزنة المندوب
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
$db = db();

// الحصول على معرف المندوب
$salesRepId = $isSalesUser ? $currentUser['id'] : (isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : null);

if (!$salesRepId) {
    die('يجب تحديد مندوب المبيعات');
}

// جلب معلومات المندوب
$salesRepInfo = $db->queryOne(
    "SELECT id, full_name, username, phone
     FROM users
     WHERE id = ? AND role = 'sales'",
    [$salesRepId]
);

if (!$salesRepInfo) {
    die('المندوب غير موجود');
}

// التحقق من وجود الجداول
$invoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
$collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
$returnsTableExists = $db->queryOne("SHOW TABLES LIKE 'returns'");
$cashAdditionsTableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
$accountantTransactionsExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");

// التحقق من وجود عمود paid_from_credit
$hasPaidFromCreditColumn = false;
if (!empty($invoicesTableExists)) {
    $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
}

// جلب جميع الفواتير
$invoices = [];
if (!empty($invoicesTableExists)) {
    // التحقق من وجود عمود credit_used
    $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
    $creditUsedSelect = $hasCreditUsedColumn ? ", COALESCE(credit_used, 0) as credit_used" : ", 0 as credit_used";
    
    $invoices = $db->query(
        "SELECT id, invoice_number, date, total_amount, paid_amount, status, paid_from_credit{$creditUsedSelect},
                customer_id, (SELECT name FROM customers WHERE id = invoices.customer_id) as customer_name,
                amount_added_to_sales
         FROM invoices
         WHERE sales_rep_id = ? AND status != 'cancelled'
         ORDER BY date DESC, id DESC",
        [$salesRepId]
    ) ?: [];
}

// جلب جميع التحصيلات
$collections = [];
if (!empty($collectionsTableExists)) {
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    $statusFilter = $hasStatusColumn ? "AND status IN ('pending', 'approved')" : "";
    
    $collections = $db->query(
        "SELECT c.*, 
                u.full_name as collected_by_name,
                (SELECT name FROM customers WHERE id = c.customer_id) as customer_name
         FROM collections c
         LEFT JOIN users u ON c.collected_by = u.id
         WHERE c.collected_by = ? $statusFilter
         ORDER BY c.date DESC, c.id DESC",
        [$salesRepId]
    ) ?: [];
}

// جلب جميع المرتجعات
$returns = [];
if (!empty($returnsTableExists)) {
    $returns = $db->query(
        "SELECT r.*,
                (SELECT invoice_number FROM invoices WHERE id = r.invoice_id) as invoice_number,
                (SELECT name FROM customers WHERE id = r.customer_id) as customer_name
         FROM returns r
         WHERE r.sales_rep_id = ? AND r.status IN ('approved', 'processed')
         ORDER BY r.return_date DESC, r.id DESC",
        [$salesRepId]
    ) ?: [];
}

// جلب الإضافات المباشرة للخزنة
$cashAdditions = [];
if (!empty($cashAdditionsTableExists)) {
    $cashAdditions = $db->query(
        "SELECT cra.*,
                u.full_name as created_by_name,
                u.username as created_by_username
         FROM cash_register_additions cra
         LEFT JOIN users u ON cra.created_by = u.id
         WHERE cra.sales_rep_id = ?
         ORDER BY cra.created_at DESC",
        [$salesRepId]
    ) ?: [];
}

// جلب المبالغ المحصلة من المندوب
$collectedFromRep = [];
if (!empty($accountantTransactionsExists)) {
    $collectedFromRep = $db->query(
        "SELECT at.*,
                u.full_name as collected_by_name,
                accountant.full_name as accountant_name
         FROM accountant_transactions at
         LEFT JOIN users u ON at.created_by = u.id
         LEFT JOIN users accountant ON at.approved_by = accountant.id
         WHERE at.sales_rep_id = ? 
         AND at.transaction_type = 'collection_from_sales_rep'
         AND at.status = 'approved'
         ORDER BY at.created_at DESC",
        [$salesRepId]
    ) ?: [];
}

// حساب الإجماليات
$totalSales = 0.0;
$totalPaid = 0.0;
$totalCollections = 0.0;
$totalReturns = 0.0;
$totalCashAdditions = 0.0;
$totalCollectedFromRep = 0.0;

foreach ($invoices as $inv) {
    $totalSales += (float)($inv['total_amount'] ?? 0);
    $totalPaid += (float)($inv['paid_amount'] ?? 0);
}

foreach ($collections as $col) {
    $totalCollections += (float)($col['amount'] ?? 0);
}

foreach ($returns as $ret) {
    $totalReturns += (float)($ret['refund_amount'] ?? 0);
}

foreach ($cashAdditions as $add) {
    $totalCashAdditions += (float)($add['amount'] ?? 0);
}

foreach ($collectedFromRep as $col) {
    $totalCollectedFromRep += (float)($col['amount'] ?? 0);
}

// حساب صافي المبيعات
$netSales = $totalSales - $totalReturns;

// حساب المبيعات المدفوعة بالكامل (من الفواتير) - نفس منطق cash_register.php
// استخدام amount_added_to_sales إذا كان موجوداً (للفواتير المدفوعة من الرصيد الدائن)
// أو total_amount للفواتير العادية
// ملاحظة مهمة: نستبعد الفواتير التي تم تسجيلها بالفعل في جدول collections
// لأنها موجودة في إجمالي التحصيلات ($totalCollections) ولا يجب حسابها مرتين
$fullyPaidSales = 0.0;
if (!empty($invoicesTableExists) && !empty($collectionsTableExists)) {
    // التحقق من وجود عمود amount_added_to_sales
    $hasAmountAddedToSalesColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'amount_added_to_sales'"));
    
    // التحقق من وجود عمود invoice_id في collections
    $hasInvoiceIdColumn = !empty($db->queryOne("SHOW COLUMNS FROM collections LIKE 'invoice_id'"));
    
    if ($hasAmountAddedToSalesColumn) {
        // التحقق من وجود عمود credit_used
        $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
        
        // استخدام amount_added_to_sales إذا كان محدداً، وإلا استخدام total_amount
        // هذا يضمن أن المبالغ المدفوعة من الرصيد الدائن لا تُضاف إلى خزنة المندوب
        // استبعاد الفواتير التي تم تسجيلها في collections (من خلال invoice_id أو notes)
        // عند استخدام الرصيد الدائن (paid_from_credit = 1): لا يُضاف المبلغ المستخدم من الرصيد الدائن إلى خزنة المندوب
        if ($hasInvoiceIdColumn) {
            // إذا كان هناك عمود invoice_id، نستخدمه للربط
            if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط (لا يشمل المبلغ المستخدم من الرصيد الدائن)
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1 AND credit_used > 0
                        THEN COALESCE(amount_added_to_sales, 0)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            } elseif ($hasPaidFromCreditColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1
                        THEN COALESCE(amount_added_to_sales, 0)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            } else {
                // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.invoice_id = i.id 
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     " . ($hasInvoiceIdColumn ? "AND (c.invoice_id IS NULL OR c.invoice_id != i.id) " : "") . "
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 )";
            }
            $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
        } else {
            // إذا لم يكن هناك عمود invoice_id، نستخدم notes للبحث عن رقم الفاتورة
            if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط (لا يشمل المبلغ المستخدم من الرصيد الدائن)
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1 AND credit_used > 0
                        THEN COALESCE(amount_added_to_sales, 0)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
            } elseif ($hasPaidFromCreditColumn) {
                // عند استخدام الرصيد الدائن: استخدام amount_added_to_sales فقط
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN paid_from_credit = 1
                        THEN COALESCE(amount_added_to_sales, 0)
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
            } else {
                // إذا لم يكن عمود paid_from_credit موجوداً، نستخدم amount_added_to_sales أو total_amount
                $fullyPaidSql = "SELECT COALESCE(SUM(
                    CASE 
                        WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                        THEN amount_added_to_sales 
                        ELSE total_amount 
                    END
                ), 0) as fully_paid
                 FROM invoices i
                 WHERE i.sales_rep_id = ? 
                 AND i.status = 'paid' 
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c 
                     WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                     AND c.collected_by = ?
                 )
                 AND NOT EXISTS (
                     SELECT 1 FROM collections c
                     WHERE c.customer_id = i.customer_id
                     AND c.collected_by = ?
                     AND c.date >= i.date
                     AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                     AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 )";
            }
            $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId]);
        }
        $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
    } else {
        // إذا لم يكن العمود موجوداً، نستخدم total_amount (للتوافق مع الإصدارات القديمة)
        if ($hasInvoiceIdColumn) {
            $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
             FROM invoices i
             WHERE i.sales_rep_id = ? 
             AND i.status = 'paid' 
             AND i.paid_amount >= i.total_amount
             AND i.status != 'cancelled'
             AND NOT EXISTS (
                 SELECT 1 FROM collections c 
                 WHERE c.invoice_id = i.id 
                 AND c.collected_by = ?
             )
             AND NOT EXISTS (
                 SELECT 1 FROM collections c
                 WHERE c.customer_id = i.customer_id
                 AND c.collected_by = ?
                 AND c.date >= i.date
                 AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
                 AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
             )";
            $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId, $salesRepId]);
        } else {
            $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
             FROM invoices i
             WHERE i.sales_rep_id = ? 
             AND i.status = 'paid' 
             AND i.paid_amount >= i.total_amount
             AND i.status != 'cancelled'
             AND NOT EXISTS (
                 SELECT 1 FROM collections c 
                 WHERE c.notes LIKE CONCAT('%فاتورة ', i.invoice_number, '%')
                 AND c.collected_by = ?
             )
             AND NOT EXISTS (
                 SELECT 1 FROM collections c
                 WHERE c.customer_id = i.customer_id
                 AND c.collected_by = ?
                 AND c.date >= i.date
                 AND (c.notes IS NULL OR c.notes NOT LIKE CONCAT('%فاتورة ', i.invoice_number, '%'))
                 AND (c.invoice_id IS NULL OR c.invoice_id != i.id)
             )";
            $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId, $salesRepId, $salesRepId, $salesRepId]);
        }
        $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
    }
} elseif (!empty($invoicesTableExists)) {
    // إذا لم يكن جدول collections موجوداً، نستخدم الطريقة القديمة
    $hasAmountAddedToSalesColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'amount_added_to_sales'"));
    $hasCreditUsedColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'credit_used'"));
    
    if ($hasAmountAddedToSalesColumn) {
        if ($hasPaidFromCreditColumn && $hasCreditUsedColumn) {
            $fullyPaidSql = "SELECT COALESCE(SUM(
                CASE 
                    WHEN paid_from_credit = 1 AND credit_used > 0
                    THEN COALESCE(amount_added_to_sales, 0)
                    WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                    THEN amount_added_to_sales 
                    ELSE total_amount 
                END
            ), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ? 
             AND status = 'paid' 
             AND paid_amount >= total_amount
             AND status != 'cancelled'";
        } elseif ($hasPaidFromCreditColumn) {
            $fullyPaidSql = "SELECT COALESCE(SUM(
                CASE 
                    WHEN paid_from_credit = 1
                    THEN COALESCE(amount_added_to_sales, 0)
                    WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                    THEN amount_added_to_sales 
                    ELSE total_amount 
                END
            ), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ? 
             AND status = 'paid' 
             AND paid_amount >= total_amount
             AND status != 'cancelled'";
        } else {
            $fullyPaidSql = "SELECT COALESCE(SUM(
                CASE 
                    WHEN amount_added_to_sales IS NOT NULL AND amount_added_to_sales > 0 
                    THEN amount_added_to_sales 
                    ELSE total_amount 
                END
            ), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ? 
             AND status = 'paid' 
             AND paid_amount >= total_amount
             AND status != 'cancelled'";
        }
    } else {
        $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
         FROM invoices
         WHERE sales_rep_id = ? 
         AND status = 'paid' 
         AND paid_amount >= total_amount
         AND status != 'cancelled'";
    }
    
    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId]);
    $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
}

// حساب رصيد الخزنة - نفس المنطق المستخدم في cash_register.php
// رصيد الخزنة = التحصيلات + المبيعات المدفوعة بالكامل + الإضافات المباشرة - المبالغ المحصلة من المندوب
// لا يتم خصم المرتجعات من رصيد الخزنة الإجمالي
$cashRegisterBalance = $totalCollections + $fullyPaidSales + $totalCashAdditions - $totalCollectedFromRep;

$companyName = COMPANY_NAME;
$companySubtitle = 'نظام إدارة المبيعات';
$companyAddress = 'نطاق التوزيع :  الاسكندريه - شحن لجميع انحاء الجمهوريه';
$companyPhone = '01003533905';

$salesRepName = $salesRepInfo['full_name'] ?? $salesRepInfo['username'];
$statementDate = formatDate(date('Y-m-d'));
$statementTime = date('H:i:s');
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كشف حساب شامل - خزنة المندوب</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 0;
            }
            .statement-wrapper {
                box-shadow: none;
                border: none;
            }
            .page-break {
                page-break-after: always;
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
            color: #1f2937;
        }
        
        .statement-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            padding: 32px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
        }
        
        .statement-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .brand-block {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .logo-placeholder {
            width: 80px;
            height: 80px;
            border-radius: 24px;
            background: linear-gradient(135deg,rgb(6, 59, 134) 0%,rgb(3, 71, 155) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 64px;
            font-weight: bold;
            overflow: hidden;
            position: relative;
        }
        
        .company-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 4px;
        }
        
        .logo-letter {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
            margin: 0;
        }
        
        .company-subtitle {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .statement-meta {
            text-align: left;
        }
        
        .statement-title {
            font-size: 24px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        
        .statement-date {
            font-size: 14px;
            color: #6b7280;
        }
        
        .sales-rep-info {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .sales-rep-info-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .sales-rep-info-item {
            display: flex;
            flex-direction: column;
        }
        
        .sales-rep-info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .sales-rep-info-value {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 13px;
        }
        
        .transactions-table th {
            background: #f9fafb;
            padding: 10px;
            text-align: right;
            font-weight: 600;
            font-size: 13px;
            color: #374151;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .transactions-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        
        .transactions-table tr:hover {
            background: #f9fafb;
        }
        
        .amount-positive {
            color: #059669;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #dc2626;
            font-weight: 600;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-partial {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .status-approved {
            background: #d1fae5;
            color: #065f46;
        }
        
        .summary-section {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-top: 24px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .summary-row:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 18px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 2px solid #1f2937;
        }
        
        .summary-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .summary-value {
            color: #1f2937;
            font-weight: 600;
            font-size: 16px;
        }
        
        .print-button {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: #2563eb;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #1d4ed8;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-muted {
            color: #6b7280;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="bi bi-printer me-2"></i>طباعة
    </button>
    
    <div class="statement-wrapper">
        <header class="statement-header">
            <div class="brand-block">
                <div class="logo-placeholder">
                    <img src="<?php echo getRelativeUrl('assets/icons/icon-192x192.svg'); ?>" alt="Logo" class="company-logo-img" onerror="this.onerror=null; this.src='<?php echo getRelativeUrl('assets/icons/icon-192x192.png'); ?>'; this.onerror=function(){this.style.display='none'; this.nextElementSibling.style.display='flex';};">
                    <span class="logo-letter" style="display:none;"><?php echo mb_substr($companyName, 0, 1); ?></span>
                </div>
                <div>
                    <h1 class="company-name"><?php echo htmlspecialchars($companyName); ?></h1>
                    <div class="company-subtitle"><?php echo htmlspecialchars($companySubtitle); ?></div>
                </div>
            </div>
            <div class="statement-meta">
                <div class="statement-title">كشف حساب شامل - خزنة المندوب</div>
                <div class="statement-date">تاريخ الطباعة: <?php echo $statementDate; ?> - <?php echo $statementTime; ?></div>
            </div>
        </header>
        
        <div class="sales-rep-info">
            <div class="sales-rep-info-row">
                <div class="sales-rep-info-item">
                    <div class="sales-rep-info-label">اسم المندوب</div>
                    <div class="sales-rep-info-value"><?php echo htmlspecialchars($salesRepName); ?></div>
                </div>
                <div class="sales-rep-info-item">
                    <div class="sales-rep-info-label">اسم المستخدم</div>
                    <div class="sales-rep-info-value"><?php echo htmlspecialchars($salesRepInfo['username'] ?? '-'); ?></div>
                </div>
                <div class="sales-rep-info-item">
                    <div class="sales-rep-info-label">الهاتف</div>
                    <div class="sales-rep-info-value"><?php echo htmlspecialchars($salesRepInfo['phone'] ?? '-'); ?></div>
                </div>
            </div>
        </div>
        
        <!-- الفواتير -->
        <h2 class="section-title">الفواتير (<?php echo count($invoices); ?>)</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم الفاتورة</th>
                    <th>العميل</th>
                    <th>التاريخ</th>
                    <th>الإجمالي</th>
                    <th>المدفوع</th>
                    <th>المدفوع من رصيد العميل</th>
                    <th>المتبقي</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">لا توجد فواتير</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $totalAmount = (float)($invoice['total_amount'] ?? 0);
                        $paidAmount = (float)($invoice['paid_amount'] ?? 0);
                        $creditUsed = (float)($invoice['credit_used'] ?? 0);
                        $remaining = $totalAmount - $paidAmount - $creditUsed;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($invoice['customer_name'] ?? '-'); ?></td>
                            <td><?php echo formatDate($invoice['date']); ?></td>
                            <td class="amount-positive"><?php echo formatCurrency($totalAmount); ?></td>
                            <td><?php echo formatCurrency($paidAmount); ?></td>
                            <td><?php echo formatCurrency($creditUsed); ?></td>
                            <td class="amount-negative"><?php echo formatCurrency($remaining); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $invoice['status']; ?>">
                                    <?php 
                                    $statusLabels = [
                                        'paid' => 'مدفوعة',
                                        'partial' => 'جزئي',
                                        'pending' => 'معلق',
                                        'sent' => 'مرسلة',
                                        'draft' => 'مسودة'
                                    ];
                                    echo $statusLabels[$invoice['status']] ?? $invoice['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- التحصيلات -->
        <h2 class="section-title">التحصيلات (<?php echo count($collections); ?>)</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم التحصيل</th>
                    <th>العميل</th>
                    <th>التاريخ</th>
                    <th>المبلغ</th>
                    <th>طريقة الدفع</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($collections)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">لا توجد تحصيلات</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($collections as $collection): ?>
                        <tr>
                            <td>#<?php echo $collection['id']; ?></td>
                            <td><?php echo htmlspecialchars($collection['customer_name'] ?? '-'); ?></td>
                            <td><?php echo formatDate($collection['date']); ?></td>
                            <td class="amount-positive"><?php echo formatCurrency($collection['amount']); ?></td>
                            <td>
                                <?php 
                                $methodLabels = [
                                    'cash' => 'نقدي',
                                    'bank_transfer' => 'تحويل بنكي',
                                    'check' => 'شيك'
                                ];
                                echo $methodLabels[$collection['payment_method']] ?? $collection['payment_method'];
                                ?>
                            </td>
                            <td>
                                <?php if (!empty($collection['status'])): ?>
                                    <span class="status-badge status-<?php echo $collection['status']; ?>">
                                        <?php 
                                        $statusLabels = [
                                            'approved' => 'معتمد',
                                            'pending' => 'معلق',
                                            'rejected' => 'مرفوض'
                                        ];
                                        echo $statusLabels[$collection['status']] ?? $collection['status'];
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- المرتجعات -->
        <h2 class="section-title">المرتجعات (<?php echo count($returns); ?>)</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>رقم المرتجع</th>
                    <th>رقم الفاتورة</th>
                    <th>العميل</th>
                    <th>التاريخ</th>
                    <th>المبلغ المرتجع</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($returns)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted">لا توجد مرتجعات</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($returns as $return): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($return['return_number'] ?? '#' . $return['id']); ?></td>
                            <td><?php echo htmlspecialchars($return['invoice_number'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($return['customer_name'] ?? '-'); ?></td>
                            <td><?php echo formatDate($return['return_date']); ?></td>
                            <td class="amount-negative"><?php echo formatCurrency($return['refund_amount']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $return['status']; ?>">
                                    <?php 
                                    $statusLabels = [
                                        'processed' => 'معالج',
                                        'pending' => 'معلق',
                                        'approved' => 'معتمد',
                                        'rejected' => 'مرفوض'
                                    ];
                                    echo $statusLabels[$return['status']] ?? $return['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- الإضافات المباشرة للخزنة -->
        <?php if (!empty($cashAdditions)): ?>
        <h2 class="section-title">الإضافات المباشرة للخزنة (<?php echo count($cashAdditions); ?>)</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ والوقت</th>
                    <th>المبلغ</th>
                    <th>الوصف</th>
                    <th>تمت الإضافة بواسطة</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($cashAdditions as $addition): 
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo formatDateTime($addition['created_at']); ?></td>
                        <td class="amount-positive">+ <?php echo formatCurrency($addition['amount']); ?></td>
                        <td><?php echo htmlspecialchars($addition['description'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($addition['created_by_name'] ?? $addition['created_by_username'] ?? 'غير معروف'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- المبالغ المحصلة من المندوب -->
        <?php if (!empty($collectedFromRep)): ?>
        <h2 class="section-title">المبالغ المحصلة من المندوب (<?php echo count($collectedFromRep); ?>)</h2>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ والوقت</th>
                    <th>المبلغ</th>
                    <th>الملاحظات</th>
                    <th>المحاسب</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($collectedFromRep as $col): ?>
                    <tr>
                        <td>#<?php echo $col['id']; ?></td>
                        <td><?php echo formatDateTime($col['created_at']); ?></td>
                        <td class="amount-negative">- <?php echo formatCurrency($col['amount']); ?></td>
                        <td><?php echo htmlspecialchars($col['notes'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($col['accountant_name'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- الملخص الشامل -->
        <div class="summary-section">
            <h2 class="section-title" style="margin-top: 0;">ملخص شامل لخزنة المندوب</h2>
            <div class="summary-row">
                <span class="summary-label">إجمالي المبيعات (من الفواتير)</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($totalSales); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي المدفوع من الفواتير</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($totalPaid); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي التحصيلات من العملاء</span>
                <span class="summary-value amount-positive">+ <?php echo formatCurrency($totalCollections); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">إجمالي المرتجعات</span>
                <span class="summary-value amount-negative">- <?php echo formatCurrency($totalReturns); ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label">صافي المبيعات (بعد خصم المرتجعات)</span>
                <span class="summary-value amount-positive"><?php echo formatCurrency($netSales); ?></span>
            </div>
            <?php if ($totalCashAdditions > 0): ?>
            <div class="summary-row">
                <span class="summary-label">الإضافات المباشرة للخزنة</span>
                <span class="summary-value amount-positive">+ <?php echo formatCurrency($totalCashAdditions); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($totalCollectedFromRep > 0): ?>
            <div class="summary-row">
                <span class="summary-label">المبالغ المحصلة من المندوب</span>
                <span class="summary-value amount-negative">- <?php echo formatCurrency($totalCollectedFromRep); ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-row">
                <span class="summary-label">رصيد الخزنة الإجمالي</span>
                <span class="summary-value <?php echo $cashRegisterBalance >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                    <?php echo formatCurrency(abs($cashRegisterBalance)); ?>
                </span>
            </div>
        </div>
        
        <footer style="margin-top: 32px; padding-top: 24px; border-top: 2px solid #e2e8f0; text-align: center; color: #6b7280; font-size: 14px;">
            <div style="margin-bottom: 8px;">كشف حساب شامل - جميع المعاملات</div>
            <div>لأي استفسارات يرجى التواصل على: <?php echo htmlspecialchars($companyPhone); ?></div>
        </footer>
    </div>
</body>
</html>

