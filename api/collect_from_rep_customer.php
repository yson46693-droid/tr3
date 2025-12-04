<?php
/**
 * API endpoint لتحصيل ديون عملاء المندوبين
 * يتم استدعاؤه من قبل المدير أو المحاسب
 */

declare(strict_types=1);

define('ACCESS_ALLOWED', true);

// تنظيف أي output buffer
while (ob_get_level() > 0) {
    ob_end_clean();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/invoices.php';
require_once __DIR__ . '/../includes/notifications.php';

// تسجيل جميع الطلبات
$debugFile = __DIR__ . '/../debug_collection_api.log';
file_put_contents($debugFile, "=== COLLECTION API REQUEST ===\n" . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
file_put_contents($debugFile, "Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . "\n", FILE_APPEND);
file_put_contents($debugFile, "POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

// إعداد response headers
header('Content-Type: application/json; charset=utf-8');

// التحقق من تسجيل الدخول
if (!isLoggedIn()) {
    file_put_contents($debugFile, "ERROR: Not logged in\n", FILE_APPEND);
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'يجب تسجيل الدخول أولاً'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من الصلاحيات
$currentUser = getCurrentUser();
$userRole = strtolower($currentUser['role'] ?? '');

file_put_contents($debugFile, "User role: $userRole\n", FILE_APPEND);

if (!in_array($userRole, ['manager', 'accountant'], true)) {
    file_put_contents($debugFile, "ERROR: Insufficient permissions\n", FILE_APPEND);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'غير مصرح لك بالوصول إلى هذه الصفحة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// التحقق من طريقة الطلب
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    file_put_contents($debugFile, "ERROR: Invalid method\n", FILE_APPEND);
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// معالجة التحصيل
$customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
$amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;

file_put_contents($debugFile, "Customer ID: $customerId, Amount: $amount\n", FILE_APPEND);

if ($customerId <= 0) {
    file_put_contents($debugFile, "ERROR: Invalid customer ID\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'معرف العميل غير صالح'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($amount <= 0) {
    file_put_contents($debugFile, "ERROR: Invalid amount\n", FILE_APPEND);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'يجب إدخال مبلغ تحصيل أكبر من صفر'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = db();
$transactionStarted = false;

try {
    $db->beginTransaction();
    $transactionStarted = true;
    
    file_put_contents($debugFile, "Transaction started\n", FILE_APPEND);
    
    // جلب بيانات العميل
    $customer = $db->queryOne(
        "SELECT id, name, balance, created_by, rep_id FROM customers WHERE id = ? FOR UPDATE",
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
    
    // تحديث رصيد العميل
    $db->execute(
        "UPDATE customers SET balance = ? WHERE id = ?",
        [$newBalance, $customerId]
    );
    
    file_put_contents($debugFile, "Customer balance updated\n", FILE_APPEND);
    
    // تحديد المندوب
    $customerRepId = (int)($customer['rep_id'] ?? 0);
    $customerCreatedBy = (int)($customer['created_by'] ?? 0);
    $salesRepId = null;
    if ($customerRepId > 0) {
        $salesRepId = $customerRepId;
    } elseif ($customerCreatedBy > 0) {
        $repCheck = $db->queryOne(
            "SELECT id FROM users WHERE id = ? AND role = 'sales' AND status = 'active'",
            [$customerCreatedBy]
        );
        if ($repCheck) {
            $salesRepId = $customerCreatedBy;
        }
    }
    
    // التأكد من وجود جدول accountant_transactions
    $accountantTableCheck = $db->queryOne("SHOW TABLES LIKE 'accountant_transactions'");
    if (empty($accountantTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `accountant_transactions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `transaction_type` enum('collection_from_sales_rep','expense','income','transfer','other') NOT NULL,
              `amount` decimal(15,2) NOT NULL,
              `sales_rep_id` int(11) DEFAULT NULL,
              `description` text NOT NULL,
              `reference_number` varchar(50) DEFAULT NULL,
              `payment_method` enum('cash','bank_transfer','check','other') DEFAULT 'cash',
              `status` enum('pending','approved','rejected') DEFAULT 'approved',
              `approved_by` int(11) DEFAULT NULL,
              `approved_at` timestamp NULL DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `transaction_type` (`transaction_type`),
              KEY `sales_rep_id` (`sales_rep_id`),
              KEY `status` (`status`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // الحصول على اسم المندوب
    $salesRepName = '';
    if ($salesRepId) {
        $salesRep = $db->queryOne(
            "SELECT id, full_name, username FROM users WHERE id = ? AND role = 'sales'",
            [$salesRepId]
        );
        $salesRepName = $salesRep ? ($salesRep['full_name'] ?? $salesRep['username'] ?? '') : '';
    }
    
    // توليد رقم مرجعي
    $referenceNumber = 'COL-CUST-' . $customerId . '-' . date('YmdHis');
    
    // وصف المعاملة
    $description = 'تحصيل من عميل: ' . htmlspecialchars($customer['name'] ?? '');
    if ($salesRepName) {
        $description .= ' (مندوب: ' . htmlspecialchars($salesRepName) . ')';
    }
    
    // إضافة المعاملة
    $db->execute(
        "INSERT INTO accountant_transactions (transaction_type, amount, sales_rep_id, description, reference_number, payment_method, status, approved_by, created_by, approved_at, notes)
         VALUES (?, ?, ?, ?, ?, 'cash', 'approved', ?, ?, NOW(), ?)",
        [
            'income',
            $amount,
            $salesRepId,
            $description,
            $referenceNumber,
            $currentUser['id'],
            $currentUser['id'],
            'تحصيل من قبل ' . ($currentUser['full_name'] ?? $currentUser['username'] ?? 'مدير/محاسب') . ' - لا يتم احتساب نسبة للمندوب'
        ]
    );
    
    $accountantTransactionId = $db->getLastInsertId();
    
    file_put_contents($debugFile, "Transaction saved: $accountantTransactionId\n", FILE_APPEND);
    
    // تسجيل سجل التدقيق
    logAudit(
        $currentUser['id'],
        'collect_customer_debt_by_manager_accountant',
        'accountant_transaction',
        $accountantTransactionId,
        null,
        [
            'customer_id' => $customerId,
            'customer_name' => $customer['name'] ?? '',
            'sales_rep_id' => $salesRepId,
            'amount' => $amount,
            'reference_number' => $referenceNumber,
        ]
    );
    
    // إرسال إشعار للمندوب
    if ($salesRepId && $salesRepId > 0) {
        try {
            $collectorName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'مدير/محاسب';
            $notificationTitle = 'تحصيل من عميلك';
            $notificationMessage = 'تم تحصيل مبلغ ' . formatCurrency($amount) . ' من العميل ' . htmlspecialchars($customer['name'] ?? '') . 
                                 ' بواسطة ' . htmlspecialchars($collectorName) . 
                                 ' - رقم المرجع: ' . $referenceNumber . 
                                 ' (ملاحظة: لا يتم احتساب نسبة تحصيلات على هذا المبلغ)';
            $notificationLink = getRelativeUrl('dashboard/sales.php?page=customers');
            
            createNotification(
                $salesRepId,
                $notificationTitle,
                $notificationMessage,
                'info',
                $notificationLink,
                true
            );
        } catch (Throwable $notifError) {
            error_log('Failed to send notification to sales rep: ' . $notifError->getMessage());
        }
    }
    
    // توزيع التحصيل على فواتير العميل
    $distributionResult = null;
    if (function_exists('distributeCollectionToInvoices')) {
        try {
            $distributionResult = distributeCollectionToInvoices($customerId, $amount, $currentUser['id']);
            file_put_contents($debugFile, "Invoice distribution result: " . json_encode($distributionResult, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        } catch (Throwable $distError) {
            file_put_contents($debugFile, "Error in invoice distribution: " . $distError->getMessage() . "\n", FILE_APPEND);
            error_log('Error distributing collection to invoices: ' . $distError->getMessage());
        }
    }
    
    $db->commit();
    $transactionStarted = false;
    
    file_put_contents($debugFile, "Transaction committed successfully\n", FILE_APPEND);
    
    // بناء رسالة النجاح
    $messageParts = ['تم تحصيل المبلغ بنجاح وإضافته إلى خزنة الشركة.'];
    $messageParts[] = 'رقم المرجع: ' . $referenceNumber . '.';
    if ($salesRepName) {
        $messageParts[] = 'تم إرسال إشعار للمندوب: ' . htmlspecialchars($salesRepName) . '.';
    }
    if ($distributionResult && !empty($distributionResult['updated_invoices'])) {
        $messageParts[] = 'تم تحديث ' . count($distributionResult['updated_invoices']) . ' فاتورة.';
    } elseif ($distributionResult && !empty($distributionResult['message'])) {
        $messageParts[] = 'ملاحظة: ' . $distributionResult['message'];
    }
    
    file_put_contents($debugFile, "=== SUCCESS ===\n", FILE_APPEND);
    
    echo json_encode([
        'success' => true,
        'message' => implode(' ', array_filter($messageParts)),
        'reference_number' => $referenceNumber,
        'new_balance' => $newBalance,
        'updated_invoices' => $distributionResult['updated_invoices'] ?? []
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if ($transactionStarted) {
        $db->rollback();
    }
    file_put_contents($debugFile, "ERROR (Exception): " . $e->getMessage() . "\n", FILE_APPEND);
    error_log('Collection error in API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء التحصيل: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($transactionStarted) {
        $db->rollback();
    }
    file_put_contents($debugFile, "ERROR (Throwable): " . $e->getMessage() . "\n", FILE_APPEND);
    error_log('Collection error in API: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء التحصيل: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

file_put_contents($debugFile, "=== END ===\n\n", FILE_APPEND);

