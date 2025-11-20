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
 * ضمان توافق جدول المرتجعات مع متطلبات مرتجعات الفواتير
 */
function ensureReturnSchemaSupportsInvoiceReturns(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    try {
        $db = db();
    } catch (Throwable $e) {
        return;
    }

    try {
        $saleIdColumn = $db->queryOne("SHOW COLUMNS FROM returns LIKE 'sale_id'");
        if (!empty($saleIdColumn) && strtoupper($saleIdColumn['Null'] ?? '') === 'NO') {
            $db->execute("ALTER TABLE returns MODIFY `sale_id` int(11) DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('ensureReturnSchemaSupportsInvoiceReturns sale_id alter failed: ' . $e->getMessage());
    }

    try {
        $refundColumn = $db->queryOne("SHOW COLUMNS FROM returns LIKE 'refund_method'");
        if (!empty($refundColumn)) {
            $type = $refundColumn['Type'] ?? '';
            if (stripos($type, 'company_request') === false) {
                $db->execute("ALTER TABLE returns MODIFY `refund_method` enum('cash','credit','exchange','company_request') DEFAULT 'cash'");
            }
        }
    } catch (Throwable $e) {
        error_log('ensureReturnSchemaSupportsInvoiceReturns refund_method alter failed: ' . $e->getMessage());
    }

    $ensured = true;
}

ensureReturnSchemaSupportsInvoiceReturns();

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
                     $notes = null, $createdBy = null, $invoiceId = null) {
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
            (return_number, sale_id, invoice_id, customer_id, sales_rep_id, return_date, return_type, 
             reason, reason_description, refund_amount, refund_method, status, notes, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [
                $returnNumber,
                $saleId ?: null,
                $invoiceId ?: null,
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
        $errorMessage = $e->getMessage();
        error_log("Return Creation Error: " . $errorMessage);
        error_log("Return Creation Error - Stack trace: " . $e->getTraceAsString());
        
        // عرض رسالة خطأ أكثر تفصيلاً في وضع التطوير
        $message = 'حدث خطأ في إنشاء المرتجع';
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $message .= ': ' . $errorMessage;
        }
        
        return ['success' => false, 'message' => $message, 'error_details' => $errorMessage];
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
        
        // جلب بيانات الفاتورة إذا كان المرتجع مرتبط بفاتورة
        $invoice = null;
        $invoicePaidAmount = 0.0;
        $invoiceTotalAmount = 0.0;
        $invoiceRemainingAmount = 0.0;
        
        if (!empty($return['invoice_id'])) {
            $invoice = $db->queryOne(
                "SELECT id, invoice_number, customer_id, sales_rep_id, total_amount, paid_amount, remaining_amount, status 
                 FROM invoices WHERE id = ?",
                [$return['invoice_id']]
            );
            
            if ($invoice) {
                $invoicePaidAmount = (float)($invoice['paid_amount'] ?? 0);
                $invoiceTotalAmount = (float)($invoice['total_amount'] ?? 0);
                $invoiceRemainingAmount = (float)($invoice['remaining_amount'] ?? 0);
            }
        }
        
        // حساب مبلغ المرتجع
        $refundAmount = (float)($return['refund_amount'] ?? 0);
        
        // جلب عناصر المرتجع
        $items = $db->query(
            "SELECT * FROM return_items WHERE return_id = ?",
            [$returnId]
        );
        
        // الحصول على سيارة المندوب ومخزن السيارة
        $salesRepId = (int)($return['sales_rep_id'] ?? 0);
        $vehicle = null;
        $vehicleWarehouse = null;
        
        if ($salesRepId > 0) {
            // البحث عن سيارة المندوب
            $vehicle = $db->queryOne(
                "SELECT v.id, v.vehicle_number 
                 FROM vehicles v 
                 WHERE v.driver_id = ? AND v.status = 'active' 
                 LIMIT 1",
                [$salesRepId]
            );
            
            if ($vehicle) {
                // البحث عن مخزن السيارة
                $vehicleWarehouse = $db->queryOne(
                    "SELECT w.id, w.name 
                     FROM warehouses w 
                     WHERE w.vehicle_id = ? AND w.warehouse_type = 'vehicle' AND w.status = 'active' 
                     LIMIT 1",
                    [$vehicle['id']]
                );
            }
        }
        
        // إرجاع المنتجات إلى مخزن سيارة المندوب
        if ($vehicle && $vehicleWarehouse) {
            require_once __DIR__ . '/vehicle_inventory.php';
            
            foreach ($items as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (float)$item['quantity'];
                
                // الحصول على الكمية الحالية في vehicle_inventory
                $existingInventory = $db->queryOne(
                    "SELECT quantity FROM vehicle_inventory 
                     WHERE vehicle_id = ? AND product_id = ?",
                    [$vehicle['id'], $productId]
                );
                
                $currentQuantity = $existingInventory ? (float)$existingInventory['quantity'] : 0;
                $newQuantity = $currentQuantity + $quantity;
                
                // استخدام دالة updateVehicleInventory لإضافة المنتج
                $updateResult = updateVehicleInventory(
                    $vehicle['id'],
                    $productId,
                    $newQuantity,
                    $approvedBy
                );
                
                if (!$updateResult['success']) {
                    throw new Exception('تعذر إرجاع المنتج إلى مخزن السيارة: ' . ($updateResult['message'] ?? 'خطأ غير معروف'));
                }
                
                // تسجيل حركة المخزون
                recordInventoryMovement(
                    $productId,
                    'in',
                    $quantity,
                    $vehicleWarehouse['id'],
                    'return',
                    $returnId,
                    "إرجاع مرتجع رقم {$return['return_number']} إلى مخزن سيارة المندوب",
                    $approvedBy
                );
            }
        } else {
            // إذا لم توجد سيارة للمندوب، إرجاع المنتجات للمخزون الرئيسي
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
        }
        
        // معالجة المالية حسب حالة دفع الفاتورة
        $customerId = (int)($return['customer_id'] ?? 0);
        
        // حساب مبلغ المرتجع الفعلي
        $refundAmount = (float)($return['refund_amount'] ?? 0);
        
        if ($invoice && $invoicePaidAmount > 0) {
            // الفاتورة مدفوعة (كلياً أو جزئياً)
            // المبلغ الذي يجب إرجاعه = الحد الأدنى بين المبلغ المدفوع وقيمة المرتجعات
            $amountToRefund = min($invoicePaidAmount, $refundAmount);
            
            if ($return['refund_method'] === 'cash') {
                // طريقة الإرجاع: نقداً
                // خصم المبلغ المدفوع من خزينة المندوب (من خلال collections)
                if ($salesRepId > 0 && $amountToRefund > 0) {
                    require_once __DIR__ . '/approval_system.php';
                    
                    // التحقق من رصيد خزنة المندوب
                    $cashBalance = calculateSalesRepCashBalance($salesRepId);
                    if ($cashBalance + 0.0001 < $amountToRefund) {
                        throw new Exception('رصيد خزنة المندوب لا يغطي قيمة المرتجع المطلوبة. الرصيد الحالي: ' . number_format($cashBalance, 2) . ' ج.م');
                    }
                    
                    // خصم المبلغ من خزينة المندوب
                    insertNegativeCollection($customerId, $salesRepId, $amountToRefund, $return['return_number'], $approvedBy);
                }
            } elseif ($return['refund_method'] === 'credit') {
                // طريقة الإرجاع: رصيد للعميل
                // إضافة المبلغ المدفوع إلى رصيد العميل الدائن (تقليل الدين)
                $customer = $db->queryOne("SELECT balance FROM customers WHERE id = ? FOR UPDATE", [$customerId]);
                if ($customer) {
                    $currentBalance = (float)($customer['balance'] ?? 0);
                    $newBalance = $currentBalance - $amountToRefund; // تقليل الدين (الرصيد المدين)
                    $db->execute(
                        "UPDATE customers SET balance = ? WHERE id = ?",
                        [$newBalance, $customerId]
                    );
                }
                // لا يتم خصم من خزينة المندوب
            }
            
            // خصم 2% من عمولة المندوب من المبلغ المدفوع
            if ($salesRepId > 0 && $amountToRefund > 0) {
                require_once __DIR__ . '/salary_calculator.php';
                
                $commissionDeduction = round($amountToRefund * 0.02, 2); // 2% من المبلغ المدفوع
                
                // التحقق من عدم تطبيق الخصم مسبقاً (منع الخصم المكرر)
                $existingDeduction = $db->queryOne(
                    "SELECT id FROM audit_logs 
                     WHERE action = 'return_commission_deduction' 
                     AND entity_type = 'salary' 
                     AND new_value LIKE ?",
                    ['%"return_id":' . $returnId . '%']
                );
                
                if (empty($existingDeduction) && $commissionDeduction > 0) {
                    // تحديد الشهر والسنة من تاريخ المرتجع
                    $returnDate = $return['return_date'] ?? date('Y-m-d');
                    $timestamp = strtotime($returnDate) ?: time();
                    $month = (int)date('n', $timestamp);
                    $year = (int)date('Y', $timestamp);
                    
                    // الحصول على أو إنشاء سجل الراتب
                    $summary = getSalarySummary($salesRepId, $month, $year);
                    
                    if (!$summary['exists']) {
                        $creation = createOrUpdateSalary($salesRepId, $month, $year);
                        if (!($creation['success'] ?? false)) {
                            error_log('Failed to create salary for return commission deduction: ' . ($creation['message'] ?? 'unknown error'));
                            throw new Exception('تعذر إنشاء سجل الراتب لخصم عمولة المرتجع');
                        }
                        $summary = getSalarySummary($salesRepId, $month, $year);
                        if (!($summary['exists'] ?? false)) {
                            throw new Exception('لم يتم العثور على سجل الراتب بعد إنشائه');
                        }
                    }
                    
                    $salary = $summary['salary'];
                    $salaryId = (int)($salary['id'] ?? 0);
                    
                    if ($salaryId <= 0) {
                        throw new Exception('تعذر تحديد سجل الراتب لخصم عمولة المرتجع');
                    }
                    
                    // الحصول على أسماء الأعمدة في جدول الرواتب
                    $columns = $db->query("SHOW COLUMNS FROM salaries");
                    $columnMap = [
                        'deductions' => null,
                        'total_amount' => null,
                        'updated_at' => null
                    ];
                    
                    foreach ($columns as $column) {
                        $field = $column['Field'] ?? '';
                        if ($field === 'deductions' || $field === 'total_deductions') {
                            $columnMap['deductions'] = $field;
                        } elseif ($field === 'total_amount' || $field === 'amount' || $field === 'net_total') {
                            $columnMap['total_amount'] = $field;
                        } elseif ($field === 'updated_at' || $field === 'modified_at' || $field === 'last_updated') {
                            $columnMap['updated_at'] = $field;
                        }
                    }
                    
                    // بناء استعلام التحديث
                    $updates = [];
                    $params = [];
                    
                    if ($columnMap['deductions'] !== null) {
                        $updates[] = "{$columnMap['deductions']} = COALESCE({$columnMap['deductions']}, 0) + ?";
                        $params[] = $commissionDeduction;
                    }
                    
                    if ($columnMap['total_amount'] !== null) {
                        $updates[] = "{$columnMap['total_amount']} = GREATEST(COALESCE({$columnMap['total_amount']}, 0) - ?, 0)";
                        $params[] = $commissionDeduction;
                    }
                    
                    if ($columnMap['updated_at'] !== null) {
                        $updates[] = "{$columnMap['updated_at']} = NOW()";
                    }
                    
                    if (!empty($updates)) {
                        $params[] = $salaryId;
                        $db->execute(
                            "UPDATE salaries SET " . implode(', ', $updates) . " WHERE id = ?",
                            $params
                        );
                        
                        // تحديث ملاحظات الراتب لتوثيق الخصم
                        $currentNotes = $salary['notes'] ?? '';
                            $deductionNote = "\n[خصم عمولة مرتجع]: تم خصم " . number_format($commissionDeduction, 2) . 
                                            " ج.م (2% من المبلغ المدفوع " . number_format($amountToRefund, 2) . 
                                            " ج.م) - مرتجع رقم {$return['return_number']}";
                        $newNotes = $currentNotes . $deductionNote;
                        
                        $db->execute(
                            "UPDATE salaries SET notes = ? WHERE id = ?",
                            [$newNotes, $salaryId]
                        );
                        
                        // تسجيل سجل التدقيق
                        logAudit($approvedBy, 'return_commission_deduction', 'salary', $salaryId, null, [
                            'return_id' => $returnId,
                            'return_number' => $return['return_number'],
                            'paid_amount' => $amountToRefund,
                            'deduction_amount' => $commissionDeduction,
                            'sales_rep_id' => $salesRepId
                        ]);
                    }
                }
            }
        } else {
            // الفاتورة غير مدفوعة أو العميل ما زال عليه رصيد مدين
            // خصم قيمة المرتجعات بالكامل من رصيد العميل المدين
            $customer = $db->queryOne("SELECT balance FROM customers WHERE id = ? FOR UPDATE", [$customerId]);
            if ($customer) {
                $currentBalance = (float)($customer['balance'] ?? 0);
                $newBalance = max(0, $currentBalance - $refundAmount); // تقليل الدين
                $db->execute(
                    "UPDATE customers SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );
            }
            // لا يتم خصم أي مبلغ من خزينة المندوب
        }
        
        $db->getConnection()->commit();
        
        logAudit($approvedBy, 'approve_return', 'return', $returnId, 
                 ['old_status' => $return['status']], 
                 ['new_status' => 'approved']);
        
        return ['success' => true, 'message' => 'تم الموافقة على المرتجع بنجاح'];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        error_log("Return Approval Error: " . $e->getMessage());
        error_log("Return Approval Error - Stack trace: " . $e->getTraceAsString());
        return ['success' => false, 'message' => 'حدث خطأ في الموافقة على المرتجع: ' . $e->getMessage()];
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
    $selectColumns[] = 'i.invoice_number';
    
    $sql = "SELECT " . implode(', ', $selectColumns) . "
            FROM returns r
            LEFT JOIN sales s ON r.sale_id = s.id
            LEFT JOIN invoices i ON r.invoice_id = i.id
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

