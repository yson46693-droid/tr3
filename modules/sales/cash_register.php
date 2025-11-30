<?php
/**
 * صفحة خزنة المندوب - عرض التفاصيل المالية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

require_once __DIR__ . '/table_styles.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$isSalesUser = isset($currentUser['role']) && $currentUser['role'] === 'sales';
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

// إذا كان المستخدم مندوب، عرض فقط بياناته
$salesRepId = $isSalesUser ? $currentUser['id'] : (isset($_GET['sales_rep_id']) ? intval($_GET['sales_rep_id']) : null);

// معالجة إضافة رصيد مباشر للخزنة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_cash_balance') {
    // التأكد من أن المستخدم مندوب مبيعات فقط
    if (!$isSalesUser) {
        $error = 'غير مصرح لك بإضافة رصيد للخزنة';
    } else {
        $amount = floatval($_POST['amount'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        
        if ($amount <= 0) {
            $error = 'يجب إدخال مبلغ صحيح أكبر من الصفر';
        } else {
            try {
                // التأكد من وجود جدول cash_register_additions
                $tableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
                if (empty($tableExists)) {
                    $db->execute("
                        CREATE TABLE IF NOT EXISTS `cash_register_additions` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `sales_rep_id` int(11) NOT NULL,
                          `amount` decimal(15,2) NOT NULL,
                          `description` text DEFAULT NULL,
                          `created_by` int(11) NOT NULL,
                          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (`id`),
                          KEY `sales_rep_id` (`sales_rep_id`),
                          KEY `created_at` (`created_at`),
                          CONSTRAINT `cash_register_additions_ibfk_1` FOREIGN KEY (`sales_rep_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                          CONSTRAINT `cash_register_additions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
                
                // إضافة الرصيد
                $db->execute(
                    "INSERT INTO cash_register_additions (sales_rep_id, amount, description, created_by) VALUES (?, ?, ?, ?)",
                    [$currentUser['id'], $amount, $description ?: null, $currentUser['id']]
                );
                
                // تسجيل في سجل التدقيق
                try {
                    logAudit($currentUser['id'], 'add_cash_balance', 'cash_register_addition', $db->getLastInsertId(), null, [
                        'amount' => $amount,
                        'description' => $description
                    ]);
                } catch (Throwable $auditError) {
                    error_log('Error logging audit for cash addition: ' . $auditError->getMessage());
                }
                
                $success = 'تم إضافة الرصيد إلى الخزنة بنجاح';
            } catch (Throwable $e) {
                error_log('Error adding cash balance: ' . $e->getMessage());
                $error = 'حدث خطأ في إضافة الرصيد. يرجى المحاولة لاحقاً.';
            }
        }
    }
    
    // إعادة التوجيه لتجنب إعادة إرسال النموذج
    if (!empty($success)) {
        $_SESSION['success'] = $success;
    }
    if (!empty($error)) {
        $_SESSION['error'] = $error;
    }
    
    // التحقق من أن headers لم يتم إرسالها بعد
    if (!headers_sent()) {
        $redirectUrl = $_SERVER['PHP_SELF'] . '?page=cash_register';
        if ($salesRepId && !$isSalesUser) {
            $redirectUrl .= '&sales_rep_id=' . $salesRepId;
        }
        header('Location: ' . $redirectUrl);
        exit;
    } else {
        // إذا تم إرسال headers بالفعل، فقط نترك الصفحة تكمل (سيتم عرض الرسائل عبر applyPRGPattern)
        // لا نحتاج لإعادة توجيه لأن POST قد تم معالجته مسبقاً في dashboard/sales.php
    }
}

if (!$salesRepId) {
    $error = 'يجب تحديد مندوب المبيعات';
    $salesRepId = $currentUser['id'];
}

// التحقق من وجود الجداول
$invoicesTableExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
$collectionsTableExists = $db->queryOne("SHOW TABLES LIKE 'collections'");
$salesTableExists = $db->queryOne("SHOW TABLES LIKE 'sales'");

// التحقق من وجود عمود paid_from_credit وإضافته إذا لم يكن موجوداً
$hasPaidFromCreditColumn = false;
if (!empty($invoicesTableExists)) {
    $hasPaidFromCreditColumn = !empty($db->queryOne("SHOW COLUMNS FROM invoices LIKE 'paid_from_credit'"));
    
    if (!$hasPaidFromCreditColumn) {
        try {
            $db->execute("ALTER TABLE invoices ADD COLUMN paid_from_credit TINYINT(1) DEFAULT 0 AFTER status");
            $hasPaidFromCreditColumn = true;
            error_log('Added paid_from_credit column to invoices table');
        } catch (Throwable $e) {
            error_log('Error adding paid_from_credit column: ' . $e->getMessage());
        }
    }
    
    // التحقق بأثر رجعي من الفواتير المدفوعة بالكامل وتحديثها إذا كانت مدفوعة من رصيد دائن
    if ($hasPaidFromCreditColumn) {
        try {
            // جلب الفواتير المدفوعة بالكامل التي لم يتم تحديدها كمدفوعة من رصيد دائن
            $invoicesToCheck = $db->query(
                "SELECT i.id, i.customer_id, i.date, i.total_amount, i.paid_amount, i.status, i.paid_from_credit
                 FROM invoices i
                 WHERE i.sales_rep_id = ?
                 AND i.status = 'paid'
                 AND i.paid_amount >= i.total_amount
                 AND i.status != 'cancelled'
                 AND (i.paid_from_credit IS NULL OR i.paid_from_credit = 0)
                 ORDER BY i.date ASC, i.id ASC",
                [$salesRepId]
            );
            
            $updatedCount = 0;
            foreach ($invoicesToCheck as $invoice) {
                $customerId = (int)$invoice['customer_id'];
                $invoiceDate = $invoice['date'];
                $invoiceId = (int)$invoice['id'];
                $invoiceTotal = (float)$invoice['total_amount'];
                
                // حساب الرصيد التراكمي للعميل قبل هذه الفاتورة
                // نحسب الرصيد من جميع الفواتير السابقة لهذا العميل (بترتيب زمني)
                $previousInvoices = $db->query(
                    "SELECT id, date, total_amount, paid_amount, status, paid_from_credit
                     FROM invoices
                     WHERE customer_id = ?
                     AND (date < ? OR (date = ? AND id < ?))
                     AND status != 'cancelled'
                     ORDER BY date ASC, id ASC",
                    [$customerId, $invoiceDate, $invoiceDate, $invoiceId]
                );
                
                // حساب الرصيد التراكمي قبل هذه الفاتورة
                // نبدأ من رصيد العميل الحالي ونرجع للخلف
                $currentCustomer = $db->queryOne(
                    "SELECT balance FROM customers WHERE id = ?",
                    [$customerId]
                );
                $currentBalance = (float)($currentCustomer['balance'] ?? 0);
                
                // حساب الرصيد قبل هذه الفاتورة عن طريق طرح تأثير الفواتير اللاحقة
                $balanceBeforeInvoice = $currentBalance;
                
                // جلب جميع الفواتير بعد هذه الفاتورة (بترتيب زمني عكسي)
                $laterInvoices = $db->query(
                    "SELECT id, date, total_amount, paid_amount, status, paid_from_credit
                     FROM invoices
                     WHERE customer_id = ?
                     AND (date > ? OR (date = ? AND id > ?))
                     AND status != 'cancelled'
                     ORDER BY date ASC, id ASC",
                    [$customerId, $invoiceDate, $invoiceDate, $invoiceId]
                );
                
                // طرح تأثير الفواتير اللاحقة من الرصيد الحالي
                foreach ($laterInvoices as $laterInv) {
                    $laterTotal = (float)($laterInv['total_amount'] ?? 0);
                    $laterPaid = (float)($laterInv['paid_amount'] ?? 0);
                    $laterCreditUsed = (int)($laterInv['paid_from_credit'] ?? 0);
                    
                    if ($laterCreditUsed) {
                        // إذا كانت الفاتورة اللاحقة مدفوعة من رصيد دائن، لا نطرحها
                        continue;
                    }
                    
                    // طرح الفرق بين المبلغ الإجمالي والمبلغ المدفوع من الرصيد
                    $balanceBeforeInvoice -= ($laterTotal - $laterPaid);
                }
                
                // طرح تأثير هذه الفاتورة نفسها
                $balanceBeforeInvoice -= ($invoiceTotal - (float)($invoice['paid_amount'] ?? 0));
                
                // إذا كان الرصيد قبل الفاتورة سالب (رصيد دائن) وكانت الفاتورة مدفوعة بالكامل
                // فهذا يعني أنها دفعت من الرصيد الدائن
                if ($balanceBeforeInvoice < -0.01 && $invoiceTotal > 0.01) {
                    // التحقق من أن الفاتورة استهلكت الرصيد الدائن
                    // الرصيد المتوقع بعد الفاتورة = الرصيد قبل + قيمة الفاتورة
                    $expectedBalanceAfter = $balanceBeforeInvoice + $invoiceTotal;
                    
                    // إذا كان الرصيد المتوقع قريب من الرصيد الفعلي (مع هامش خطأ صغير)
                    // فهذا يعني أن الفاتورة دفعت من رصيد دائن
                    if (abs($expectedBalanceAfter - $currentBalance) < 0.02) {
                        // تحديث الفاتورة لتحديدها كمدفوعة من رصيد دائن
                        $db->execute(
                            "UPDATE invoices SET paid_from_credit = 1 WHERE id = ?",
                            [$invoiceId]
                        );
                        $updatedCount++;
                    }
                }
            }
            
            if ($updatedCount > 0) {
                error_log("Retroactively updated $updatedCount invoices as paid_from_credit for sales rep $salesRepId");
            }
        } catch (Throwable $retroCheckError) {
            error_log('Error in retroactive credit check: ' . $retroCheckError->getMessage());
        }
    }
}

// حساب إجمالي المبيعات من الفواتير
// استخدام total_amount دائماً لعرض إجمالي المبيعات الكامل بغض النظر عن الرصيد الدائن
$totalSalesFromInvoices = 0.0;
if (!empty($invoicesTableExists)) {
    // استخدام total_amount دائماً لعرض إجمالي المبيعات الكامل
    $salesResult = $db->queryOne(
        "SELECT COALESCE(SUM(total_amount), 0) as total_sales
         FROM invoices
         WHERE sales_rep_id = ? AND status != 'cancelled'",
        [$salesRepId]
    );
    $totalSalesFromInvoices = (float)($salesResult['total_sales'] ?? 0);
}

// حساب إجمالي المبيعات من جدول sales
$totalSalesFromSalesTable = 0.0;
if (!empty($salesTableExists)) {
    $salesTableResult = $db->queryOne(
        "SELECT COALESCE(SUM(total), 0) as total_sales
         FROM sales
         WHERE salesperson_id = ? AND status IN ('approved', 'completed')",
        [$salesRepId]
    );
    $totalSalesFromSalesTable = (float)($salesTableResult['total_sales'] ?? 0);
}

// إجمالي المبيعات (نستخدم الفواتير إذا كانت موجودة، وإلا نستخدم جدول sales)
$totalSales = $totalSalesFromInvoices > 0 ? $totalSalesFromInvoices : $totalSalesFromSalesTable;

// حساب تأثير الاستبدالات على إجمالي المبيعات
$exchangesTableExists = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
$totalExchanges = 0.0;
if (!empty($exchangesTableExists)) {
    try {
        // حساب إجمالي الفرق من الاستبدالات المعتمدة
        $exchangesResult = $db->queryOne(
            "SELECT COALESCE(SUM(difference_amount), 0) as total_exchanges
             FROM exchanges
             WHERE sales_rep_id = ? 
               AND status IN ('approved', 'completed')",
            [$salesRepId]
        );
        $totalExchanges = (float)($exchangesResult['total_exchanges'] ?? 0);
        
        // إضافة/خصم الاستبدالات من إجمالي المبيعات
        // الفرق موجب = خصم، الفرق سالب = إضافة
        $totalSales = $totalSales - $totalExchanges; // سالب الفرق = إضافة إذا كان الفرق سالب
    } catch (Throwable $exchangesError) {
        error_log('Exchanges calculation error for total sales: ' . $exchangesError->getMessage());
    }
}

// حساب إجمالي المرتجعات للمندوب
$totalReturns = 0.0;
$returnsTableExists = $db->queryOne("SHOW TABLES LIKE 'returns'");
if (!empty($returnsTableExists)) {
    try {
        // حساب المرتجعات المعتمدة والمعالجة فقط
        $returnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) as total_returns
             FROM returns
             WHERE sales_rep_id = ? 
               AND status IN ('approved', 'processed')
               AND refund_amount > 0",
            [$salesRepId]
        );
        $totalReturns = (float)($returnsResult['total_returns'] ?? 0);
    } catch (Throwable $returnsError) {
        error_log('Returns calculation error: ' . $returnsError->getMessage());
        $totalReturns = 0.0;
    }
}

// حساب صافي المبيعات (إجمالي المبيعات - المرتجعات)
$netSales = $totalSales - $totalReturns;

// حساب إجمالي التحصيلات
$totalCollections = 0.0;
if (!empty($collectionsTableExists)) {
    // التحقق من وجود عمود status
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    if ($hasStatusColumn) {
        // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ? AND status IN ('pending', 'approved')",
            [$salesRepId]
        );
    } else {
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ?",
            [$salesRepId]
        );
    }
    $totalCollections = (float)($collectionsResult['total_collections'] ?? 0);
}

// حساب المبيعات المدفوعة بالكامل (من الفواتير)
// استخدام total_amount دائماً لعرض إجمالي المبيعات المدفوعة بالكامل
$fullyPaidSales = 0.0;
if (!empty($invoicesTableExists)) {
    // استخدام total_amount دائماً لجميع الفواتير المدفوعة بالكامل
    $fullyPaidSql = "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
         FROM invoices
         WHERE sales_rep_id = ? 
         AND status = 'paid' 
         AND paid_amount >= total_amount
         AND status != 'cancelled'";
    
    $fullyPaidResult = $db->queryOne($fullyPaidSql, [$salesRepId]);
    $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
}

// حساب المبالغ المحصلة من المندوب (من accountant_transactions) لخصمها من الرصيد
$collectedFromRep = 0.0;
$accountantTransactionsExists = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
if (!empty($accountantTransactionsExists)) {
    $collectedResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total_collected
         FROM accountant_transactions
         WHERE sales_rep_id = ? 
         AND transaction_type = 'collection_from_sales_rep'
         AND status = 'approved'",
        [$salesRepId]
    );
    $collectedFromRep = (float)($collectedResult['total_collected'] ?? 0);
}

// حساب إجمالي المرتجعات التالفة للمندوب
$totalDamagedReturns = 0.0;
$damagedReturnsTableExists = $db->queryOne("SHOW TABLES LIKE 'damaged_returns'");
if (!empty($damagedReturnsTableExists)) {
    try {
        // حساب قيمة المرتجعات التالفة المعتمدة
        // نحسب القيمة من return_items المرتبطة بالمرتجعات التالفة
        $damagedReturnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(ri.total_price), 0) as total_damaged_returns
             FROM damaged_returns dr
             INNER JOIN return_items ri ON dr.return_item_id = ri.id
             WHERE dr.sales_rep_id = ? 
               AND dr.approval_status = 'approved'",
            [$salesRepId]
        );
        $totalDamagedReturns = (float)($damagedReturnsResult['total_damaged_returns'] ?? 0);
    } catch (Throwable $damagedReturnsError) {
        error_log('Damaged returns calculation error: ' . $damagedReturnsError->getMessage());
        $totalDamagedReturns = 0.0;
    }
}

// حساب الإضافات المباشرة للرصيد
$totalCashAdditions = 0.0;
$cashAdditionsTableExists = $db->queryOne("SHOW TABLES LIKE 'cash_register_additions'");
if (!empty($cashAdditionsTableExists)) {
    try {
        $additionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_additions
             FROM cash_register_additions
             WHERE sales_rep_id = ?",
            [$salesRepId]
        );
        $totalCashAdditions = (float)($additionsResult['total_additions'] ?? 0);
    } catch (Throwable $additionsError) {
        error_log('Cash additions calculation error: ' . $additionsError->getMessage());
        $totalCashAdditions = 0.0;
    }
}

// رصيد الخزنة = التحصيلات + المبيعات المدفوعة بالكامل + الإضافات المباشرة - المبالغ المحصلة من المندوب
// لا يتم خصم المرتجعات من رصيد الخزنة الإجمالي
$cashRegisterBalance = $totalCollections + $fullyPaidSales + $totalCashAdditions - $collectedFromRep;

// حساب المبيعات المعلقة (الديون) بالاعتماد على أرصدة العملاء لضمان التطابق مع صفحة العملاء
$pendingSales = 0.0;
$customerDebtResult = null;
try {
    $customerDebtSql = "SELECT COALESCE(SUM(balance), 0) AS total_debt FROM customers WHERE balance > 0";
    $customerDebtParams = [];
    
    // إذا كان هناك مندوب محدد، نعرض العملاء الخاصين به فقط (نفس منطق صفحة العملاء)
    if ($salesRepId) {
        $customerDebtSql .= " AND created_by = ?";
        $customerDebtParams[] = $salesRepId;
    }
    
    $customerDebtResult = $db->queryOne($customerDebtSql, $customerDebtParams);
    $pendingSales = (float)($customerDebtResult['total_debt'] ?? 0);
} catch (Throwable $customerDebtError) {
    // في حالة حدوث خطأ، نستخدم الطريقة القديمة كحل احتياطي
    error_log('Sales cash register debt calculation fallback: ' . $customerDebtError->getMessage());
    $pendingSales = $totalSales - $fullyPaidSales - $totalCollections;
    if ($pendingSales < 0) {
        $pendingSales = 0;
    }
}

// إحصائيات إضافية
$todaySales = 0.0;
$monthSales = 0.0;
$todayCollections = 0.0;
$monthCollections = 0.0;

if (!empty($invoicesTableExists)) {
    // حساب مبيعات اليوم (جميع الفواتير بغض النظر عن الرصيد الدائن)
    $todaySalesSql = "SELECT COALESCE(SUM(total_amount), 0) as total
         FROM invoices
         WHERE sales_rep_id = ? AND DATE(date) = CURDATE() AND status != 'cancelled'";
    
    $todaySalesResult = $db->queryOne($todaySalesSql, [$salesRepId]);
    $todaySales = (float)($todaySalesResult['total'] ?? 0);
    
    // حساب مبيعات الشهر (جميع الفواتير بغض النظر عن الرصيد الدائن)
    $monthSalesSql = "SELECT COALESCE(SUM(total_amount), 0) as total
         FROM invoices
         WHERE sales_rep_id = ? 
         AND MONTH(date) = MONTH(NOW()) 
         AND YEAR(date) = YEAR(NOW())
         AND status != 'cancelled'";
    
    $monthSalesResult = $db->queryOne($monthSalesSql, [$salesRepId]);
    $monthSales = (float)($monthSalesResult['total'] ?? 0);
}

// خصم قيمة المرتجعات من مبيعات اليوم والشهر
if (!empty($returnsTableExists)) {
    try {
        // حساب المرتجعات اليومية
        $todayReturnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) as total_returns
             FROM returns
             WHERE sales_rep_id = ? 
               AND DATE(return_date) = CURDATE()
               AND status IN ('approved', 'processed')
               AND refund_amount > 0",
            [$salesRepId]
        );
        $todayReturns = (float)($todayReturnsResult['total_returns'] ?? 0);
        
        // خصم المرتجعات اليومية من مبيعات اليوم
        $todaySales = max(0, $todaySales - $todayReturns);
        
        // حساب المرتجعات الشهرية
        $monthReturnsResult = $db->queryOne(
            "SELECT COALESCE(SUM(refund_amount), 0) as total_returns
             FROM returns
             WHERE sales_rep_id = ? 
               AND MONTH(return_date) = MONTH(NOW()) 
               AND YEAR(return_date) = YEAR(NOW())
               AND status IN ('approved', 'processed')
               AND refund_amount > 0",
            [$salesRepId]
        );
        $monthReturns = (float)($monthReturnsResult['total_returns'] ?? 0);
        
        // خصم المرتجعات الشهرية من مبيعات الشهر
        $monthSales = max(0, $monthSales - $monthReturns);
    } catch (Throwable $returnsError) {
        error_log('Returns calculation error for daily/monthly sales: ' . $returnsError->getMessage());
    }
}

// حساب تأثير الاستبدالات على مبيعات اليوم والشهر
$exchangesTableExists = $db->queryOne("SHOW TABLES LIKE 'exchanges'");
if (!empty($exchangesTableExists)) {
    try {
        // حساب الاستبدالات اليومية (الفرق الإجمالي)
        $todayExchangesResult = $db->queryOne(
            "SELECT COALESCE(SUM(difference_amount), 0) as total_exchanges
             FROM exchanges
             WHERE sales_rep_id = ? 
               AND DATE(exchange_date) = CURDATE()
               AND status IN ('approved', 'completed')",
            [$salesRepId]
        );
        $todayExchanges = (float)($todayExchangesResult['total_exchanges'] ?? 0);
        
        // إضافة/خصم الاستبدالات اليومية من مبيعات اليوم
        // الفرق موجب = خصم، الفرق سالب = إضافة
        $todaySales = $todaySales - $todayExchanges; // سالب الفرق = إضافة إذا كان الفرق سالب
        
        // حساب الاستبدالات الشهرية
        $monthExchangesResult = $db->queryOne(
            "SELECT COALESCE(SUM(difference_amount), 0) as total_exchanges
             FROM exchanges
             WHERE sales_rep_id = ? 
               AND MONTH(exchange_date) = MONTH(NOW()) 
               AND YEAR(exchange_date) = YEAR(NOW())
               AND status IN ('approved', 'completed')",
            [$salesRepId]
        );
        $monthExchanges = (float)($monthExchangesResult['total_exchanges'] ?? 0);
        
        // إضافة/خصم الاستبدالات الشهرية من مبيعات الشهر
        $monthSales = $monthSales - $monthExchanges; // سالب الفرق = إضافة إذا كان الفرق سالب
    } catch (Throwable $exchangesError) {
        error_log('Exchanges calculation error for daily/monthly sales: ' . $exchangesError->getMessage());
    }
}

if (!empty($collectionsTableExists)) {
    $statusColumnCheck = $db->queryOne("SHOW COLUMNS FROM collections LIKE 'status'");
    $hasStatusColumn = !empty($statusColumnCheck);
    
    // حساب جميع التحصيلات (pending و approved) لأن المندوب قام بتحصيلها بالفعل
    $statusFilter = $hasStatusColumn ? "AND status IN ('pending', 'approved')" : "";
    
    $todayCollectionsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total
         FROM collections
         WHERE collected_by = ? AND DATE(date) = CURDATE() $statusFilter",
        [$salesRepId]
    );
    $todayCollections = (float)($todayCollectionsResult['total'] ?? 0);
    
    $monthCollectionsResult = $db->queryOne(
        "SELECT COALESCE(SUM(amount), 0) as total
         FROM collections
         WHERE collected_by = ? 
         AND MONTH(date) = MONTH(NOW()) 
         AND YEAR(date) = YEAR(NOW())
         $statusFilter",
        [$salesRepId]
    );
    $monthCollections = (float)($monthCollectionsResult['total'] ?? 0);
}

// حساب الديون القديمة (العملاء المدينين بدون سجل مشتريات)
$oldDebtsCustomers = [];
$oldDebtsTotal = 0.0;

try {
    // التحقق من وجود جدول customer_purchase_history
    $purchaseHistoryTableExists = $db->queryOne("SHOW TABLES LIKE 'customer_purchase_history'");
    
    if (!empty($purchaseHistoryTableExists)) {
        // جلب العملاء المدينين الذين ليس لديهم سجل مشتريات
        $oldDebtsQuery = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at
            FROM customers c
            WHERE c.created_by = ?
            AND c.balance IS NOT NULL 
            AND c.balance > 0
            AND NOT EXISTS (
                SELECT 1 
                FROM customer_purchase_history cph 
                WHERE cph.customer_id = c.id
            )
            ORDER BY c.balance DESC, c.name ASC
        ";
        
        $oldDebtsCustomers = $db->query($oldDebtsQuery, [$salesRepId]);
        
        // حساب إجمالي الديون القديمة
        if (!empty($oldDebtsCustomers)) {
            foreach ($oldDebtsCustomers as $customer) {
                $oldDebtsTotal += (float)($customer['balance'] ?? 0);
            }
        }
    } else {
        // إذا لم يكن الجدول موجوداً، نستخدم استعلام مختلف
        // جلب العملاء المدينين الذين ليس لديهم فواتير
        $oldDebtsQuery = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                c.address,
                c.balance,
                c.created_at
            FROM customers c
            WHERE c.created_by = ?
            AND c.balance IS NOT NULL 
            AND c.balance > 0
            AND NOT EXISTS (
                SELECT 1 
                FROM invoices inv 
                WHERE inv.customer_id = c.id
            )
            ORDER BY c.balance DESC, c.name ASC
        ";
        
        $oldDebtsCustomers = $db->query($oldDebtsQuery, [$salesRepId]);
        
        // حساب إجمالي الديون القديمة
        if (!empty($oldDebtsCustomers)) {
            foreach ($oldDebtsCustomers as $customer) {
                $oldDebtsTotal += (float)($customer['balance'] ?? 0);
            }
        }
    }
} catch (Throwable $oldDebtsError) {
    error_log('Old debts calculation error: ' . $oldDebtsError->getMessage());
    $oldDebtsCustomers = [];
    $oldDebtsTotal = 0.0;
}

// جلب معلومات المندوب
$salesRepInfo = $db->queryOne(
    "SELECT id, full_name, username, email, phone
     FROM users
     WHERE id = ? AND role = 'sales'",
    [$salesRepId]
);

?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-stack me-2"></i>خزنة المندوب</h2>
    <?php if ($salesRepInfo): ?>
        <div class="text-muted">
            <i class="bi bi-person-circle me-2"></i>
            <strong><?php echo htmlspecialchars($salesRepInfo['full_name'] ?? $salesRepInfo['username']); ?></strong>
        </div>
    <?php endif; ?>
</div>

<!-- بطاقات الإحصائيات الرئيسية -->
<div class="row g-3 mb-4">
    
</div>

<!-- بطاقات إحصائيات إضافية -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">مبيعات اليوم</div>
                <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($todaySales); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">مبيعات الشهر</div>
                <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($monthSales); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">تحصيلات اليوم</div>
                <div class="fs-5 fw-bold mb-0 text-success"><?php echo formatCurrency($todayCollections); ?></div>
            </div>
        </div>
    </div>
    
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold">تحصيلات الشهر</div>
                <div class="fs-5 fw-bold mb-0 text-success"><?php echo formatCurrency($monthCollections); ?></div>
            </div>
        </div>
    </div>
</div>



<!-- ملخص الحسابات -->
<style>
/* Glassmorphism Styles for Cash Register */
.glass-card {
    background: rgba(255, 255, 255, 0.7);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    overflow: hidden;
    position: relative;
}

.glass-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 16px 48px rgba(0, 0, 0, 0.15);
}

.glass-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.glass-card-header i {
    font-size: 24px;
}

.glass-card-body {
    padding: 24px 20px;
}

.glass-card-value {
    font-size: 32px;
    font-weight: 700;
    line-height: 1.2;
    margin: 0;
}

.glass-card-title {
    font-size: 14px;
    font-weight: 600;
    color: #6b7280;
    margin: 0;
    margin-bottom: 8px;
}

.glass-card-blue {
    color: #0057ff;
}

.glass-card-green {
    color: #0fa55a;
}

.glass-card-orange {
    color: #c98200;
}

.glass-card-red {
    color: #d00000;
}

.glass-card-red-bg {
    background: rgba(208, 0, 0, 0.1);
    border-color: rgba(208, 0, 0, 0.2);
}

.glass-card-red-bg .glass-card-header {
    background: rgba(208, 0, 0, 0.05);
}

.glass-debts-table {
    background: rgba(255, 255, 255, 0.5);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-radius: 16px;
    overflow: hidden;
    margin-top: 20px;
}

.glass-debts-table table {
    margin: 0;
}

.glass-debts-table thead th {
    background: rgba(208, 0, 0, 0.08);
    border: none;
    padding: 16px;
    font-weight: 600;
    color: #374151;
}

.glass-debts-table tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.glass-debts-table tbody tr:last-child td {
    border-bottom: none;
}

.glass-debts-table tbody tr:hover {
    background: rgba(208, 0, 0, 0.03);
}

.glass-debts-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    margin-bottom: 16px;
}

.glass-debts-summary i {
    font-size: 20px;
    color: #d00000;
}

@media (max-width: 768px) {
    .glass-card-value {
        font-size: 24px;
    }
    
    .glass-card-header {
        padding: 14px 16px;
    }
    
    .glass-card-body {
        padding: 20px 16px;
    }
}
</style>

<div class="mb-4">
    <div class="row g-4">
        <!-- إجمالي المبيعات (صافي بعد خصم المرتجعات) -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-bar-chart-fill glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي المبيعات (صافي)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0"><?php echo formatCurrency($netSales); ?></p>
                    <?php if ($totalReturns > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        إجمالي المبيعات: <?php echo formatCurrency($totalSales); ?> - المرتجعات: <?php echo formatCurrency($totalReturns); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- التحصيلات من العملاء -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-wallet-fill glass-card-green"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي التحصيلات من العملاء</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-green mb-0">+ <?php echo formatCurrency($totalCollections); ?></p>
                </div>
            </div>
        </div>
        
        <!-- مبيعات مدفوعة بالكامل -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-cash-coin glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">مبيعات مدفوعة بالكامل (بدون ديون)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0">+ <?php echo formatCurrency($fullyPaidSales); ?></p>
                </div>
            </div>
        </div>
        
        <!-- المبيعات المعلقة -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-exclamation-triangle-fill glass-card-orange"></i>
                    <h6 class="mb-0 fw-semibold">الديون المعلقة (يشمل الديون القديمه)</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-orange mb-0">- <?php echo formatCurrency($pendingSales); ?></p>
                    <?php if ($oldDebtsTotal > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        ديون بدون سجل مشتريات: <?php echo formatCurrency($oldDebtsTotal); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- إجمالي المرتجعات -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-arrow-return-left glass-card-red"></i>
                    <h6 class="mb-0 fw-semibold">إجمالي المرتجعات</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-red mb-0">- <?php echo formatCurrency($totalReturns); ?></p>
                    <?php if ($totalDamagedReturns > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        منها تالفة: <?php echo formatCurrency($totalDamagedReturns); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- المرتجعات التالفة -->
        <?php if ($totalDamagedReturns > 0): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-exclamation-triangle glass-card-red"></i>
                    <h6 class="mb-0 fw-semibold">المرتجعات التالفة</h6>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-red mb-0">- <?php echo formatCurrency($totalDamagedReturns); ?></p>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        قيمة المنتجات التالفة المعتمدة
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- رصيد الخزنة الإجمالي -->
        <div class="col-12 col-md-6 col-lg-4">
            <div class="glass-card">
                <div class="glass-card-header">
                    <i class="bi bi-bank glass-card-blue"></i>
                    <h6 class="mb-0 fw-semibold">رصيد الخزنة الإجمالي</h6>
                    <?php if ($isSalesUser): ?>
                    <button type="button" class="btn btn-sm btn-light ms-auto" data-bs-toggle="modal" data-bs-target="#addCashBalanceModal" title="إضافة رصيد">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <div class="glass-card-body">
                    <p class="glass-card-value glass-card-blue mb-0"><?php echo formatCurrency($cashRegisterBalance); ?></p>
                    <?php if ($totalCashAdditions > 0): ?>
                    <p class="glass-card-title mt-2 mb-0" style="font-size: 12px;">
                        إضافات مباشرة: <?php echo formatCurrency($totalCashAdditions); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- جدول الديون القديمة -->
<div class="glass-card glass-card-red-bg mb-4">
    <div class="glass-card-header">
        <i class="bi bi-clipboard-x-fill glass-card-red"></i>
        <h5 class="mb-0 fw-bold">الديون القديمة</h5>
    </div>
    <div class="glass-card-body">
        <div class="glass-debts-summary">
            <i class="bi bi-people-fill"></i>
            <div>
                <p class="mb-1 text-muted small">العملاء المدينين الذين ليس لديهم سجل مشتريات في النظام.</p>
                <div class="d-flex align-items-center gap-4 flex-wrap">
                    <div>
                        <span class="text-muted small">عدد العملاء: </span>
                        <strong><?php echo count($oldDebtsCustomers); ?></strong>
                    </div>
                    <div>
                        <span class="text-muted small">إجمالي الديون: </span>
                        <strong class="glass-card-red fs-5"><?php echo formatCurrency($oldDebtsTotal); ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($oldDebtsCustomers)): ?>
            <div class="glass-debts-table">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>
                                    <i class="bi bi-person-fill me-2"></i>اسم العميل
                                </th>
                                <th>
                                    <i class="bi bi-telephone-fill me-2"></i>الهاتف
                                </th>
                                <th>
                                    <i class="bi bi-geo-alt-fill me-2"></i>العنوان
                                </th>
                                <th class="text-end">
                                    <i class="bi bi-cash-stack me-2"></i>الديون
                                </th>
                                <th>
                                    <i class="bi bi-calendar-event-fill me-2"></i>تاريخ الإضافة
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($oldDebtsCustomers as $customer): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($customer['phone'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($customer['address'] ?? '-'); ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <strong class="glass-card-red">
                                            <?php echo formatCurrency((float)($customer['balance'] ?? 0)); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            if (!empty($customer['created_at'])) {
                                                echo date('Y-m-d', strtotime($customer['created_at']));
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: rgba(208, 0, 0, 0.1);">
                                <th colspan="3" class="text-end">
                                    <strong>الإجمالي:</strong>
                                </th>
                                <th class="text-end">
                                    <strong class="glass-card-red"><?php echo formatCurrency($oldDebtsTotal); ?></strong>
                                </th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0" style="border-radius: 12px;">
                <i class="bi bi-check-circle me-2"></i>
                لا توجد ديون قديمة للعملاء المدينين بدون سجل مشتريات.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة رصيد للخزنة -->
<?php if ($isSalesUser): ?>
<div class="modal fade" id="addCashBalanceModal" tabindex="-1" aria-labelledby="addCashBalanceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addCashBalanceModalLabel">
                    <i class="bi bi-plus-circle me-2"></i>إضافة رصيد للخزنة
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" id="addCashBalanceForm">
                <input type="hidden" name="action" value="add_cash_balance">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="cashBalanceAmount" class="form-label">المبلغ <span class="text-danger">*</span></label>
                        <input type="number" 
                               class="form-control" 
                               id="cashBalanceAmount" 
                               name="amount" 
                               step="0.01" 
                               min="0.01" 
                               required 
                               placeholder="أدخل المبلغ">
                        <div class="form-text">يجب أن يكون المبلغ أكبر من الصفر</div>
                    </div>
                    <div class="mb-3">
                        <label for="cashBalanceDescription" class="form-label">الوصف</label>
                        <textarea class="form-control" 
                                  id="cashBalanceDescription" 
                                  name="description" 
                                  rows="3" 
                                  placeholder="اكتب وصفاً للمبلغ (اختياري)"></textarea>
                        <div class="form-text">مثال: إضافة رصيد نقدي، إيداع من حساب شخصي، إلخ</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>إضافة الرصيد
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();

// إعادة تعيين نموذج إضافة الرصيد عند إغلاق الـ modal
(function() {
    const addCashBalanceModal = document.getElementById('addCashBalanceModal');
    const addCashBalanceForm = document.getElementById('addCashBalanceForm');
    
    if (addCashBalanceModal && addCashBalanceForm) {
        addCashBalanceModal.addEventListener('hidden.bs.modal', function() {
            addCashBalanceForm.reset();
        });
    }
})();
</script>

