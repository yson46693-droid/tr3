<?php
/**
 * نظام الفواتير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';

/**
 * إنشاء فاتورة جديدة
 */
function createInvoice($customerId, $salesRepId, $date, $items, $taxRate = 0, $discountAmount = 0, $notes = null, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        if (!$createdBy) {
            return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
        }
        
        // توليد رقم فاتورة
        $invoiceNumber = generateInvoiceNumber();
        
        // حساب المبالغ
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += ($item['quantity'] * $item['unit_price']);
        }
        
        $taxAmount = 0; // تم إلغاء الضريبة
        $totalAmount = $subtotal - $discountAmount;
        
        // حساب تاريخ الاستحقاق (إذا لم يتم تحديده، استخدم null لطباعة "أجل غير مسمى")
        if (empty($dueDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $dueDate = null;
        }
        
        // إنشاء الفاتورة
        $sql = "INSERT INTO invoices 
                (invoice_number, customer_id, sales_rep_id, date, due_date, subtotal, tax_rate, tax_amount, 
                 discount_amount, total_amount, notes, created_by, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')";
        
        $result = $db->execute($sql, [
            $invoiceNumber,
            $customerId,
            $salesRepId,
            $date,
            $dueDate,
            $subtotal,
            $taxRate,
            $taxAmount,
            $discountAmount,
            $totalAmount,
            $notes,
            $createdBy
        ]);
        
        $invoiceId = $result['insert_id'];
        
        // إضافة عناصر الفاتورة
        foreach ($items as $item) {
            $itemTotal = $item['quantity'] * $item['unit_price'];
            
            $db->execute(
                "INSERT INTO invoice_items (invoice_id, product_id, description, quantity, unit_price, total_price) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $invoiceId,
                    $item['product_id'],
                    $item['description'] ?? null,
                    $item['quantity'],
                    $item['unit_price'],
                    $itemTotal
                ]
            );
        }
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'create_invoice', 'invoice', $invoiceId, null, [
            'invoice_number' => $invoiceNumber,
            'total_amount' => $totalAmount
        ]);
        
        return ['success' => true, 'invoice_id' => $invoiceId, 'invoice_number' => $invoiceNumber, 'total_amount' => $totalAmount];
        
    } catch (Exception $e) {
        error_log("Invoice Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء الفاتورة'];
    }
}

/**
 * توليد رقم فاتورة
 */
function generateInvoiceNumber() {
    $db = db();
    
    $year = date('Y');
    $month = date('m');
    
    // الحصول على آخر رقم فاتورة لهذا الشهر
    $result = $db->queryOne(
        "SELECT invoice_number FROM invoices 
         WHERE invoice_number LIKE ? 
         ORDER BY invoice_number DESC LIMIT 1",
        ["INV-{$year}{$month}-%"]
    );
    
    if ($result) {
        // استخراج الرقم التسلسلي
        $parts = explode('-', $result['invoice_number']);
        $serial = intval($parts[2] ?? 0) + 1;
    } else {
        $serial = 1;
    }
    
    return sprintf("INV-%s%s-%04d", $year, $month, $serial);
}

/**
 * الحصول على فاتورة
 */
function getInvoice($invoiceId) {
    $db = db();
    
    $invoice = $db->queryOne(
        "SELECT i.*, c.name as customer_name, c.phone as customer_phone, c.address as customer_address,
                u.full_name as sales_rep_name, u2.username as created_by_name
         FROM invoices i
         LEFT JOIN customers c ON i.customer_id = c.id
         LEFT JOIN users u ON i.sales_rep_id = u.id
         LEFT JOIN users u2 ON i.created_by = u2.id
         WHERE i.id = ?",
        [$invoiceId]
    );
    
    if ($invoice) {
        $invoice['items'] = $db->query(
            "SELECT ii.*, p.name as product_name, p.unit
             FROM invoice_items ii
             LEFT JOIN products p ON ii.product_id = p.id
             WHERE ii.invoice_id = ?
             ORDER BY ii.id",
            [$invoiceId]
        );
    }
    
    return $invoice;
}

/**
 * تحديث حالة الفاتورة
 */
function updateInvoiceStatus($invoiceId, $status, $updatedBy = null) {
    try {
        $db = db();
        
        if ($updatedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $updatedBy = $currentUser['id'] ?? null;
        }
        
        $oldInvoice = getInvoice($invoiceId);
        
        $sql = "UPDATE invoices SET status = ?, updated_at = NOW() WHERE id = ?";
        $db->execute($sql, [$status, $invoiceId]);
        
        // إذا تم الدفع، تحديث paid_amount
        if ($status === 'paid') {
            $db->execute(
                "UPDATE invoices SET paid_amount = total_amount, updated_at = NOW() WHERE id = ?",
                [$invoiceId]
            );
            
            // إرسال إشعار
            createNotification(
                $oldInvoice['created_by'],
                'تم دفع الفاتورة',
                "تم دفع الفاتورة رقم {$oldInvoice['invoice_number']}",
                'success',
                "dashboard/accountant.php?page=invoices&id={$invoiceId}"
            );
        }
        
        // تسجيل سجل التدقيق
        logAudit($updatedBy, 'update_invoice_status', 'invoice', $invoiceId, 
                 ['old_status' => $oldInvoice['status']], 
                 ['new_status' => $status]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Update Invoice Status Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تحديث حالة الفاتورة'];
    }
}

/**
 * تسجيل دفعة على فاتورة
 */
function recordInvoicePayment($invoiceId, $amount, $notes = null, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        $invoice = getInvoice($invoiceId);
        
        if (!$invoice) {
            return ['success' => false, 'message' => 'الفاتورة غير موجودة'];
        }
        
        $newPaidAmount = $invoice['paid_amount'] + $amount;
        
        // تحديث المبلغ المدفوع
        $db->execute(
            "UPDATE invoices SET paid_amount = ?, updated_at = NOW() WHERE id = ?",
            [$newPaidAmount, $invoiceId]
        );
        
        // إذا تم دفع المبلغ بالكامل، تحديث الحالة
        if ($newPaidAmount >= $invoice['total_amount']) {
            updateInvoiceStatus($invoiceId, 'paid', $createdBy);
        } else {
            updateInvoiceStatus($invoiceId, 'sent', $createdBy);
        }
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'invoice_payment', 'invoice', $invoiceId, 
                 ['old_paid' => $invoice['paid_amount']], 
                 ['new_paid' => $newPaidAmount, 'payment' => $amount]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Invoice Payment Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الدفعة'];
    }
}

/**
 * توزيع التحصيل على فواتير العميل وتحديثها
 * يتم توزيع المبلغ على الفواتير من الأقدم للأحدث
 */
function distributeCollectionToInvoices($customerId, $amount, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        // الحصول على فواتير العميل التي لم يتم دفعها بالكامل، مرتبة من الأقدم للأحدث
        $invoices = $db->query(
            "SELECT id, invoice_number, total_amount, paid_amount, status 
             FROM invoices 
             WHERE customer_id = ? 
             AND status NOT IN ('paid', 'cancelled')
             AND (total_amount - paid_amount) > 0
             ORDER BY date ASC, created_at ASC",
            [$customerId]
        );
        
        if (empty($invoices)) {
            return ['success' => true, 'message' => 'لا توجد فواتير معلقة للعميل'];
        }
        
        $remainingAmount = $amount;
        $updatedInvoices = [];
        
        foreach ($invoices as $invoice) {
            if ($remainingAmount <= 0) {
                break;
            }
            
            $invoiceRemaining = $invoice['total_amount'] - $invoice['paid_amount'];
            $paymentAmount = min($remainingAmount, $invoiceRemaining);
            
            // تحديث الفاتورة
            $newPaidAmount = $invoice['paid_amount'] + $paymentAmount;
            $newRemaining = $invoice['total_amount'] - $newPaidAmount;
            
            // تحديد الحالة الجديدة
            $newStatus = $invoice['status'];
            if ($newRemaining <= 0.0001) {
                $newStatus = 'paid';
            } elseif ($newPaidAmount > 0 && $invoice['status'] === 'draft') {
                $newStatus = 'sent';
            } elseif ($newPaidAmount > 0 && $invoice['status'] === 'sent') {
                $newStatus = 'partial';
            }
            
            // تحديث الفاتورة
            $db->execute(
                "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE id = ?",
                [$newPaidAmount, $newRemaining, $newStatus, $invoice['id']]
            );
            
            // تسجيل سجل التدقيق
            logAudit($createdBy, 'invoice_payment_from_collection', 'invoice', $invoice['id'], 
                     ['old_paid' => $invoice['paid_amount'], 'old_status' => $invoice['status']], 
                     ['new_paid' => $newPaidAmount, 'new_status' => $newStatus, 'payment' => $paymentAmount]);
            
            $updatedInvoices[] = [
                'invoice_id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'payment_amount' => $paymentAmount,
                'new_status' => $newStatus
            ];
            
            $remainingAmount -= $paymentAmount;
        }
        
        return [
            'success' => true,
            'updated_invoices' => $updatedInvoices,
            'remaining_amount' => $remainingAmount
        ];
        
    } catch (Exception $e) {
        error_log("Distribute Collection Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في توزيع التحصيل على الفواتير'];
    }
}

/**
 * الحصول على قائمة الفواتير
 */
function getInvoices($filters = [], $limit = 50, $offset = 0) {
    $db = db();
    
    $sql = "SELECT i.*, c.name as customer_name, u.full_name as sales_rep_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            LEFT JOIN users u ON i.sales_rep_id = u.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND i.customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND i.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(i.date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(i.date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    if (!empty($filters['invoice_number'])) {
        $sql .= " AND i.invoice_number LIKE ?";
        $params[] = "%{$filters['invoice_number']}%";
    }
    
    $sql .= " ORDER BY i.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على عدد الفواتير
 */
function getInvoicesCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM invoices WHERE 1=1";
    $params = [];
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

/**
 * حذف فاتورة
 */
function deleteInvoice($invoiceId, $deletedBy = null) {
    try {
        $db = db();
        
        if ($deletedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $deletedBy = $currentUser['id'] ?? null;
        }
        
        $invoice = getInvoice($invoiceId);
        
        if ($invoice['status'] === 'paid') {
            return ['success' => false, 'message' => 'لا يمكن حذف فاتورة مدفوعة'];
        }
        
        // حذف عناصر الفاتورة
        $db->execute("DELETE FROM invoice_items WHERE invoice_id = ?", [$invoiceId]);
        
        // حذف الفاتورة
        $db->execute("DELETE FROM invoices WHERE id = ?", [$invoiceId]);
        
        // تسجيل سجل التدقيق
        logAudit($deletedBy, 'delete_invoice', 'invoice', $invoiceId, json_encode($invoice), null);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Delete Invoice Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في حذف الفاتورة'];
    }
}

/**
 * إنشاء فاتورة PDF لتسوية مرتب موظف
 */
function generateSalarySettlementInvoice($settlementId, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementDate, $notes = null) {
    try {
        require_once __DIR__ . '/path_helper.php';
        
        $db = db();
        
        // الحصول على بيانات التسوية
        $settlement = $db->queryOne(
            "SELECT ss.*, u.full_name as employee_name, u.username, creator.full_name as created_by_name
             FROM salary_settlements ss
             LEFT JOIN users u ON ss.user_id = u.id
             LEFT JOIN users creator ON ss.created_by = creator.id
             WHERE ss.id = ?",
            [$settlementId]
        );
        
        if (!$settlement) {
            return null;
        }
        
        $companyName = COMPANY_NAME ?? 'شركة';
        $invoiceNumber = 'SETT-' . date('Ymd') . '-' . str_pad($settlementId, 4, '0', STR_PAD_LEFT);
        
        // إنشاء محتوى HTML للفاتورة
        $html = generateSalarySettlementInvoiceHTML($settlement, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementDate, $notes, $companyName, $invoiceNumber);
        
        // حفظ الفاتورة كملف HTML
        $invoicesDir = __DIR__ . '/../invoices/salary_settlements/';
        if (!is_dir($invoicesDir)) {
            mkdir($invoicesDir, 0755, true);
        }
        
        $filename = 'settlement_' . $settlementId . '_' . date('YmdHis') . '.html';
        $filepath = $invoicesDir . $filename;
        
        file_put_contents($filepath, $html);
        
        // إرجاع المسار النسبي
        return 'invoices/salary_settlements/' . $filename;
        
    } catch (Exception $e) {
        error_log('Error generating salary settlement invoice: ' . $e->getMessage());
        return null;
    }
}

/**
 * توليد HTML فاتورة تسوية المرتب
 */
function generateSalarySettlementInvoiceHTML($settlement, $salary, $settlementAmount, $previousAccumulated, $remainingAfter, $settlementDate, $notes, $companyName, $invoiceNumber) {
    $settlementTypeLabel = $settlement['settlement_type'] === 'full' ? 'تسوية كاملة' : 'تسوية جزئية';
    $formattedDate = formatDate($settlementDate);
    
    $html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاتورة تسوية مرتب - ' . htmlspecialchars($invoiceNumber) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%);
            padding: 20px;
            color: #1e293b;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .invoice-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 30%, #60a5fa 60%, #93c5fd 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .invoice-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .invoice-header .invoice-number {
            font-size: 18px;
            opacity: 0.9;
        }
        .invoice-body {
            padding: 30px;
        }
        .info-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-box {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border-right: 4px solid #3b82f6;
        }
        .info-box label {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
        }
        .info-box .value {
            font-size: 18px;
            font-weight: bold;
            color: #1e293b;
        }
        .amounts-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .amounts-table th {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 15px;
            text-align: right;
            font-weight: 600;
        }
        .amounts-table td {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        .amounts-table tr:last-child td {
            border-bottom: none;
        }
        .amount-primary { color: #3b82f6; font-weight: bold; }
        .amount-success { color: #10b981; font-weight: bold; }
        .amount-warning { color: #f59e0b; font-weight: bold; }
        .notes-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border-right: 4px solid #10b981;
        }
        .notes-section h3 {
            color: #1e293b;
            margin-bottom: 10px;
        }
        .footer {
            padding: 20px 30px;
            background: #f8fafc;
            text-align: center;
            color: #64748b;
            font-size: 12px;
        }
        @media print {
            body { background: white; padding: 0; }
            .invoice-container { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <h1>فاتورة تسوية مرتب موظف</h1>
            <div class="invoice-number">رقم الفاتورة: ' . htmlspecialchars($invoiceNumber) . '</div>
        </div>
        <div class="invoice-body">
            <div class="info-section">
                <div class="info-box">
                    <label>الموظف</label>
                    <div class="value">' . htmlspecialchars($settlement['employee_name'] ?? $settlement['username'] ?? 'غير محدد') . '</div>
                </div>
                <div class="info-box">
                    <label>تاريخ التسوية</label>
                    <div class="value">' . htmlspecialchars($formattedDate) . '</div>
                </div>
                <div class="info-box">
                    <label>نوع التسوية</label>
                    <div class="value">' . htmlspecialchars($settlementTypeLabel) . '</div>
                </div>
                <div class="info-box">
                    <label>من قام بالتسوية</label>
                    <div class="value">' . htmlspecialchars($settlement['created_by_name'] ?? 'غير محدد') . '</div>
                </div>
            </div>
            
            <table class="amounts-table">
                <thead>
                    <tr>
                        <th>الوصف</th>
                        <th>المبلغ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>المبلغ التراكمي السابق</strong></td>
                        <td class="amount-primary">' . number_format($previousAccumulated, 2) . ' ج.م</td>
                    </tr>
                    <tr>
                        <td><strong>المبلغ المسدد</strong></td>
                        <td class="amount-success">' . number_format($settlementAmount, 2) . ' ج.م</td>
                    </tr>
                    <tr>
                        <td><strong>المتبقي بعد التسوية</strong></td>
                        <td class="amount-warning">' . number_format($remainingAfter, 2) . ' ج.م</td>
                    </tr>
                </tbody>
            </table>';
    
    if ($notes) {
        $html .= '
            <div class="notes-section">
                <h3>ملاحظات</h3>
                <p>' . nl2br(htmlspecialchars($notes)) . '</p>
            </div>';
    }
    
    $html .= '
        </div>
        <div class="footer">
            <p>' . htmlspecialchars($companyName) . ' - ' . date('Y') . '</p>
            <p>تم إنشاء هذه الفاتورة تلقائياً من النظام</p>
        </div>
    </div>
</body>
</html>';
    
    return $html;
}

