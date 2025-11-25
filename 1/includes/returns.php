<?php
/**
 * نظام المرتجعات
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/inventory_movements.php';

/**
 * توليد رقم مرتجع
 */
function generateReturnNumber() {
    $db = db();
    $prefix = 'RET-' . date('Ym');
    $lastReturn = $db->queryOne(
        "SELECT return_number FROM returns WHERE return_number LIKE ? ORDER BY return_number DESC LIMIT 1",
        [$prefix . '%']
    );
    
    $serial = 1;
    if ($lastReturn) {
        $parts = explode('-', $lastReturn['return_number']);
        $serial = intval($parts[2] ?? 0) + 1;
    }
    
    return sprintf("%s-%04d", $prefix, $serial);
}

/**
 * إنشاء مرتجع جديد
 */
function createReturn($saleId, $customerId, $salesRepId, $returnDate, $returnType, 
                     $reason, $reasonDescription, $items, $refundMethod = 'cash', 
                     $notes = null, $createdBy = null) {
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
        
        $returnNumber = generateReturnNumber();
        $refundAmount = 0;
        
        foreach ($items as $item) {
            $refundAmount += ($item['quantity'] * $item['unit_price']);
        }
        
        $db->execute(
            "INSERT INTO returns 
            (return_number, sale_id, customer_id, sales_rep_id, return_date, return_type, 
             reason, reason_description, refund_amount, refund_method, status, notes, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [
                $returnNumber,
                $saleId,
                $customerId,
                $salesRepId,
                $returnDate,
                $returnType,
                $reason,
                $reasonDescription,
                $refundAmount,
                $refundMethod,
                $notes,
                $createdBy
            ]
        );
        
        $returnId = $db->getLastInsertId();
        
        // إضافة عناصر المرتجع
        foreach ($items as $item) {
            $db->execute(
                "INSERT INTO return_items 
                (return_id, product_id, quantity, unit_price, total_price, condition, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $returnId,
                    $item['product_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['quantity'] * $item['unit_price'],
                    $item['condition'] ?? 'new',
                    $item['notes'] ?? null
                ]
            );
        }
        
        // إرسال إشعار للمديرين للموافقة
        notifyManagers(
            'مرتجع جديد',
            "تم إنشاء مرتجع جديد رقم {$returnNumber} من العميل",
            'info',
            "dashboard/manager.php?page=returns&id={$returnId}"
        );
        
        logAudit($createdBy, 'create_return', 'return', $returnId, null, [
            'return_number' => $returnNumber,
            'refund_amount' => $refundAmount
        ]);
        
        return ['success' => true, 'return_id' => $returnId, 'return_number' => $returnNumber];
        
    } catch (Exception $e) {
        error_log("Return Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء المرتجع'];
    }
}

/**
 * الموافقة على مرتجع
 */
function approveReturn($returnId, $approvedBy = null) {
    try {
        $db = db();
        
        if ($approvedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $approvedBy = $currentUser['id'] ?? null;
        }
        
        $return = $db->queryOne("SELECT * FROM returns WHERE id = ?", [$returnId]);
        
        if (!$return) {
            return ['success' => false, 'message' => 'المرتجع غير موجود'];
        }
        
        if ($return['status'] !== 'pending') {
            return ['success' => false, 'message' => 'تم معالجة هذا المرتجع بالفعل'];
        }
        
        $db->getConnection()->begin_transaction();
        
        // تحديث حالة المرتجع
        $db->execute(
            "UPDATE returns 
             SET status = 'approved', approved_by = ?, approved_at = NOW() 
             WHERE id = ?",
            [$approvedBy, $returnId]
        );
        
        // إرجاع المنتجات للمخزون
        $items = $db->query(
            "SELECT * FROM return_items WHERE return_id = ?",
            [$returnId]
        );
        
        foreach ($items as $item) {
            recordInventoryMovement(
                $item['product_id'],
                'in',
                $item['quantity'],
                null,
                'return',
                $returnId,
                "إرجاع مرتجع رقم {$return['return_number']}",
                $approvedBy
            );
        }
        
        // معالجة الاسترداد
        if ($return['refund_method'] === 'cash' || $return['refund_method'] === 'credit') {
            // يمكن إضافة منطق معالجة الاسترداد هنا
        }
        
        $db->getConnection()->commit();
        
        logAudit($approvedBy, 'approve_return', 'return', $returnId, 
                 ['old_status' => $return['status']], 
                 ['new_status' => 'approved']);
        
        return ['success' => true, 'message' => 'تم الموافقة على المرتجع بنجاح'];
        
    } catch (Exception $e) {
        $db->getConnection()->rollback();
        error_log("Return Approval Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في الموافقة على المرتجع'];
    }
}

/**
 * الحصول على المرتجعات
 */
function getReturns($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    // التحقق من وجود عمود sale_number في جدول sales
    $saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
    $hasSaleNumberColumn = !empty($saleNumberColumnCheck);
    
    // بناء SELECT بشكل ديناميكي
    $selectColumns = ['r.*'];
    if ($hasSaleNumberColumn) {
        $selectColumns[] = 's.sale_number';
    } else {
        $selectColumns[] = 's.id as sale_number';
    }
    $selectColumns[] = 'c.name as customer_name';
    $selectColumns[] = 'u.full_name as sales_rep_name';
    $selectColumns[] = 'u2.full_name as approved_by_name';
    
    $sql = "SELECT " . implode(', ', $selectColumns) . "
            FROM returns r
            LEFT JOIN sales s ON r.sale_id = s.id
            LEFT JOIN customers c ON r.customer_id = c.id
            LEFT JOIN users u ON r.sales_rep_id = u.id
            LEFT JOIN users u2 ON r.approved_by = u2.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND r.customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['sales_rep_id'])) {
        $sql .= " AND r.sales_rep_id = ?";
        $params[] = $filters['sales_rep_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND r.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(r.return_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(r.return_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

