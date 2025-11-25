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
        
        // حساب تاريخ الاستحقاق (افتراضي: 30 يوم)
        $dueDate = date('Y-m-d', strtotime($date . ' +30 days'));
        
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

