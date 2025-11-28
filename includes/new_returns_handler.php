<?php
/**
 * معالج نظام المرتجعات الجديد
 * New Returns Handler
 * 
 * هذا الملف يحتوي على الوظائف الأساسية لمعالجة طلبات الإرجاع
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/approval_system.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';

/**
 * توليد رقم مرتجع فريد
 */
function generateNewReturnNumber(): string
{
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
 * الحصول على الكمية المشتراة الأصلية من الفاتورة
 */
function getOriginalPurchaseQuantity(int $invoiceId, int $productId, ?int $batchNumberId = null): float
{
    $db = db();
    
    $sql = "SELECT COALESCE(SUM(ii.quantity), 0) as total_quantity
            FROM invoice_items ii
            WHERE ii.invoice_id = ? AND ii.product_id = ?";
    
    $params = [$invoiceId, $productId];
    
    // إذا كان هناك رقم تشغيلة، نحتاج للبحث في sales_batch_numbers
    if ($batchNumberId) {
        $sql .= " AND EXISTS (
                    SELECT 1 FROM sales_batch_numbers sbn 
                    WHERE sbn.invoice_item_id = ii.id 
                    AND sbn.batch_number_id = ?
                )";
        $params[] = $batchNumberId;
    }
    
    $result = $db->queryOne($sql, $params);
    return (float)($result['total_quantity'] ?? 0);
}

/**
 * الحصول على الكمية المرتجعة بالفعل من نفس الفاتورة
 */
function getAlreadyReturnedQuantity(int $invoiceId, int $productId, ?int $batchNumberId = null): float
{
    $db = db();
    
    // البحث في المرتجعات المعتمدة فقط
    $sql = "SELECT COALESCE(SUM(ri.quantity), 0) as total_returned
            FROM return_items ri
            INNER JOIN returns r ON ri.return_id = r.id
            WHERE r.invoice_id = ? 
            AND ri.product_id = ?
            AND r.status IN ('approved', 'processed', 'completed')";
    
    $params = [$invoiceId, $productId];
    
    if ($batchNumberId) {
        $sql .= " AND ri.batch_number_id = ?";
        $params[] = $batchNumberId;
    }
    
    $result = $db->queryOne($sql, $params);
    return (float)($result['total_returned'] ?? 0);
}

/**
 * التحقق من صحة بيانات الإرجاع
 */
function validateReturnRequest(array $items, int $invoiceId): array
{
    $errors = [];
    
    foreach ($items as $index => $item) {
        if (!isset($item['product_id']) || !isset($item['quantity'])) {
            $errors[] = "عنصر #" . ($index + 1) . ": بيانات غير مكتملة";
            continue;
        }
        
        $productId = (int)$item['product_id'];
        $quantity = (float)$item['quantity'];
        $batchNumberId = isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null;
        
        if ($quantity <= 0) {
            $errors[] = "عنصر #" . ($index + 1) . ": الكمية يجب أن تكون أكبر من الصفر";
            continue;
        }
        
        // الحصول على الكمية الأصلية والمرتجعة
        $originalQuantity = getOriginalPurchaseQuantity($invoiceId, $productId, $batchNumberId);
        $returnedQuantity = getAlreadyReturnedQuantity($invoiceId, $productId, $batchNumberId);
        $availableToReturn = $originalQuantity - $returnedQuantity;
        
        if ($quantity > $availableToReturn) {
            $errors[] = sprintf(
                "عنصر #%d: الكمية المرتجعة (%.2f) أكبر من المتاح للإرجاع (%.2f)",
                $index + 1,
                $quantity,
                $availableToReturn
            );
        }
    }
    
    if (empty($errors)) {
        return ['success' => true];
    }
    
    return [
        'success' => false,
        'message' => implode("\n", $errors),
        'errors' => $errors
    ];
}

/**
 * إنشاء طلب إرجاع جديد
 * 
 * @param int $customerId معرف العميل
 * @param int $invoiceId معرف الفاتورة
 * @param array $items عناصر الإرجاع [['product_id' => int, 'quantity' => float, 'unit_price' => float, 'batch_number_id' => ?int, 'is_damaged' => bool, 'damage_reason' => ?string], ...]
 * @param string $reason سبب الإرجاع
 * @param string|null $reasonDescription وصف تفصيلي
 * @param string|null $notes ملاحظات إضافية
 * @param int|null $createdBy معرف المستخدم المنشئ (null يعني المستخدم الحالي)
 * @return array ['success' => bool, 'return_id' => int|null, 'return_number' => string|null, 'message' => string]
 */
function createReturnRequest(
    int $customerId,
    int $invoiceId,
    array $items,
    string $reason = 'customer_request',
    ?string $reasonDescription = null,
    ?string $notes = null,
    ?int $createdBy = null
): array {
    try {
        $db = db();
        
        // الحصول على المستخدم الحالي
        if ($createdBy === null) {
            $currentUser = getCurrentUser();
            if (!$currentUser) {
                return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
            }
            $createdBy = (int)$currentUser['id'];
        }
        
        // التحقق من وجود الفاتورة وجلب بياناتها
        $invoice = $db->queryOne(
            "SELECT id, invoice_number, customer_id, sales_rep_id, total_amount, paid_amount, status
             FROM invoices 
             WHERE id = ? AND customer_id = ?",
            [$invoiceId, $customerId]
        );
        
        if (!$invoice) {
            return ['success' => false, 'message' => 'الفاتورة غير موجودة أو غير مرتبطة بهذا العميل'];
        }
        
        $salesRepId = (int)($invoice['sales_rep_id'] ?? 0);
        
        // التحقق من صحة بيانات الإرجاع
        $validation = validateReturnRequest($items, $invoiceId);
        if (!$validation['success']) {
            return $validation;
        }
        
        // حساب إجمالي مبلغ المرتجع
        $totalRefundAmount = 0.0;
        $totalQuantity = 0.0;
        $hasDamagedItems = false;
        
        foreach ($items as $item) {
            $quantity = (float)$item['quantity'];
            $unitPrice = (float)$item['unit_price'];
            $totalRefundAmount += ($quantity * $unitPrice);
            $totalQuantity += $quantity;
            
            if (!empty($item['is_damaged'])) {
                $hasDamagedItems = true;
            }
        }
        
        // تحديد نوع المرتجع (كامل/جزئي)
        $invoiceTotal = (float)$invoice['total_amount'];
        $returnType = abs($totalRefundAmount - $invoiceTotal) < 0.01 ? 'full' : 'partial';
        
        // توليد رقم المرتجع
        $returnNumber = generateNewReturnNumber();
        
        // بدء المعاملة
        $conn = $db->getConnection();
        $conn->begin_transaction();
        
        try {
            // إنشاء سجل المرتجع
            // Check if return_quantity and condition_type columns exist
            $hasReturnQuantity = false;
            $hasConditionType = false;
            try {
                $columns = $db->query("SHOW COLUMNS FROM returns LIKE 'return_quantity'");
                $hasReturnQuantity = !empty($columns);
            } catch (Throwable $e) {
                $hasReturnQuantity = false;
            }
            
            try {
                $columns = $db->query("SHOW COLUMNS FROM returns LIKE 'condition_type'");
                $hasConditionType = !empty($columns);
            } catch (Throwable $e) {
                $hasConditionType = false;
            }
            
            // Build INSERT statement dynamically based on available columns
            if ($hasReturnQuantity && $hasConditionType) {
                $db->execute(
                    "INSERT INTO returns 
                    (return_number, invoice_id, customer_id, sales_rep_id, return_date, return_type, 
                     reason, reason_description, refund_amount, refund_method, status, 
                     return_quantity, condition_type, notes, created_by) 
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'credit', 'pending', ?, ?, ?, ?)",
                    [
                        $returnNumber,
                        $invoiceId,
                        $customerId,
                        $salesRepId ?: null,
                        $returnType,
                        $reason,
                        $reasonDescription,
                        $totalRefundAmount,
                        $totalQuantity,
                        $hasDamagedItems ? 'damaged' : 'normal',
                        $notes,
                        $createdBy
                    ]
                );
            } else {
                // Standard INSERT without return_quantity and condition_type
                $db->execute(
                    "INSERT INTO returns 
                    (return_number, invoice_id, customer_id, sales_rep_id, return_date, return_type, 
                     reason, reason_description, refund_amount, refund_method, status, notes, created_by) 
                    VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, 'credit', 'pending', ?, ?)",
                    [
                        $returnNumber,
                        $invoiceId,
                        $customerId,
                        $salesRepId ?: null,
                        $returnType,
                        $reason,
                        $reasonDescription,
                        $totalRefundAmount,
                        $notes,
                        $createdBy
                    ]
                );
            }
            
            $returnId = (int)$db->getLastInsertId();
            
            // إضافة عناصر المرتجع
            foreach ($items as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $totalPrice = $quantity * $unitPrice;
                $batchNumberId = isset($item['batch_number_id']) && $item['batch_number_id'] ? (int)$item['batch_number_id'] : null;
                $batchNumber = $item['batch_number'] ?? null;
                $invoiceItemId = isset($item['invoice_item_id']) && $item['invoice_item_id'] ? (int)$item['invoice_item_id'] : null;
                $isDamaged = !empty($item['is_damaged']) ? 1 : 0;
                $condition = $item['condition'] ?? ($isDamaged ? 'damaged' : 'new');
                
                // إدراج عنصر المرتجع
                $db->execute(
                    "INSERT INTO return_items 
                    (return_id, invoice_item_id, product_id, quantity, unit_price, total_price, 
                     batch_number_id, batch_number, condition, is_damaged, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $returnId,
                        $invoiceItemId,
                        $productId,
                        $quantity,
                        $unitPrice,
                        $totalPrice,
                        $batchNumberId,
                        $batchNumber,
                        $condition,
                        $isDamaged,
                        isset($item['damage_reason']) ? $item['damage_reason'] : null
                    ]
                );
                
                $returnItemId = (int)$db->getLastInsertId();
                
                // إذا كان المنتج تالفاً، إضافة إلى جدول المرتجعات التالفة
                if ($isDamaged) {
                    $db->execute(
                        "INSERT INTO damaged_returns 
                        (return_id, return_item_id, product_id, batch_number_id, quantity, damage_reason) 
                        VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            $returnId,
                            $returnItemId,
                            $productId,
                            $batchNumberId,
                            $quantity,
                            $item['damage_reason'] ?? null
                        ]
                    );
                }
            }
            
            // إنشاء طلب موافقة
            $entityColumn = getApprovalsEntityColumn();
            $approvalNotes = "مرتجع فاتورة رقم: {$invoice['invoice_number']}\n";
            $approvalNotes .= "رقم المرتجع: {$returnNumber}\n";
            $approvalNotes .= "المبلغ الإجمالي: " . number_format($totalRefundAmount, 2) . " ج.م";
            
            $approvalResult = requestApproval('return_request', $returnId, $createdBy, $approvalNotes);
            
            if (!$approvalResult['success']) {
                throw new Exception('فشل إنشاء طلب الموافقة: ' . ($approvalResult['message'] ?? 'خطأ غير معروف'));
            }
            
            // تسجيل في سجل التدقيق
            logAudit($createdBy, 'create_return_request', 'returns', $returnId, null, [
                'return_number' => $returnNumber,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoice['invoice_number'],
                'refund_amount' => $totalRefundAmount,
                'item_count' => count($items)
            ]);
            
            // إرسال إشعار للمديرين
            notifyManagers(
                'طلب مرتجع جديد',
                "تم إنشاء طلب مرتجع جديد رقم {$returnNumber} من العميل يحتاج للموافقة",
                'info',
                "dashboard/manager.php?page=approvals&section=returns"
            );
            
            // تأكيد المعاملة
            $conn->commit();
            
            return [
                'success' => true,
                'return_id' => $returnId,
                'return_number' => $returnNumber,
                'message' => 'تم إنشاء طلب الإرجاع بنجاح وتم إرساله للمدير للموافقة'
            ];
            
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("Error creating return request: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'حدث خطأ أثناء إنشاء طلب الإرجاع: ' . $e->getMessage()
            ];
        }
        
    } catch (Throwable $e) {
        error_log("Error in createReturnRequest: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'حدث خطأ غير متوقع: ' . $e->getMessage()
        ];
    }
}

