<?php
/**
 * نظام الموافقات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/audit_log.php';

if (!function_exists('getApprovalsEntityColumn')) {
    /**
     * تحديد اسم عمود هوية الكيان في جدول الموافقات (لدعم قواعد بيانات أقدم).
     */
    function getApprovalsEntityColumn(): string
    {
        static $column = null;

        if ($column !== null) {
            return $column;
        }

        try {
            $db = db();
        } catch (Throwable $e) {
            $column = 'entity_id';
            return $column;
        }

        $candidates = ['entity_id', 'reference_id', 'record_id', 'request_id', 'approval_entity', 'entity_ref'];

        foreach ($candidates as $candidate) {
            try {
                $result = $db->queryOne("SHOW COLUMNS FROM approvals LIKE ?", [$candidate]);
            } catch (Throwable $columnError) {
                $result = null;
            }

            if (!empty($result)) {
                $column = $candidate;
                return $column;
            }
        }

        // البحث عن أي عمود ينتهي بـ _id باستثناء الأعمدة المعروفة
        try {
            $columns = $db->query("SHOW COLUMNS FROM approvals") ?? [];
        } catch (Throwable $columnsError) {
            $columns = [];
        }

        $exclude = [
            'id',
            'requested_by',
            'approved_by',
            'created_by',
            'user_id',
            'manager_id',
            'accountant_id',
        ];

        foreach ($columns as $columnInfo) {
            $name = $columnInfo['Field'] ?? '';
            $lower = strtolower($name);
            if (in_array($lower, $exclude, true)) {
                continue;
            }
            if (substr($lower, -3) === '_id') {
                $column = $name;
                return $column;
            }
        }

        $column = 'entity_id';
        return $column;
    }
}

/**
 * طلب موافقة
 */
function requestApproval($type, $entityId, $requestedBy, $notes = null) {
    try {
        $db = db();
        $entityColumn = getApprovalsEntityColumn();
        
        // التحقق من وجود موافقة معلقة
        $existing = $db->queryOne(
            "SELECT id FROM approvals 
             WHERE type = ? AND {$entityColumn} = ? AND status = 'pending'",
            [$type, $entityId]
        );
        
        if ($existing) {
            return ['success' => false, 'message' => 'يوجد موافقة معلقة بالفعل'];
        }
        
        // إنشاء موافقة جديدة
        // التحقق من اسم عمود الملاحظات
        $notesColumn = 'notes';
        $columns = $db->query("SHOW COLUMNS FROM approvals") ?? [];
        $hasNotesColumn = false;
        $hasApprovalNotesColumn = false;
        
        foreach ($columns as $column) {
            $fieldName = $column['Field'] ?? '';
            if ($fieldName === 'notes') {
                $hasNotesColumn = true;
            } elseif ($fieldName === 'approval_notes') {
                $hasApprovalNotesColumn = true;
                $notesColumn = 'approval_notes';
            }
        }
        
        // بناء استعلام الإدراج بناءً على الأعمدة المتاحة
        if ($hasNotesColumn || $hasApprovalNotesColumn) {
            $sql = "INSERT INTO approvals (type, {$entityColumn}, requested_by, status, {$notesColumn}) 
                    VALUES (?, ?, ?, 'pending', ?)";
            
            $result = $db->execute($sql, [
                $type,
                $entityId,
                $requestedBy,
                $notes
            ]);
        } else {
            // إذا لم يكن هناك عمود ملاحظات، إدراج بدون ملاحظات
            $sql = "INSERT INTO approvals (type, {$entityColumn}, requested_by, status) 
                    VALUES (?, ?, ?, 'pending')";
            
            $result = $db->execute($sql, [
                $type,
                $entityId,
                $requestedBy
            ]);
        }
        
        // إرسال إشعار للمديرين
        $entityName = getEntityName($type, $entityId);
        
        // تحسين رسالة الإشعار لطلبات نقل المنتجات
        if ($type === 'warehouse_transfer') {
            $transferNumber = '';
            $transferDetails = '';
            try {
                $transfer = $db->queryOne("SELECT transfer_number, from_warehouse_id, to_warehouse_id, transfer_date FROM warehouse_transfers WHERE id = ?", [$entityId]);
                if ($transfer) {
                    if (!empty($transfer['transfer_number'])) {
                        $transferNumber = ' رقم ' . $transfer['transfer_number'];
                    }
                    
                    // الحصول على أسماء المخازن
                    $fromWarehouse = $db->queryOne("SELECT name FROM warehouses WHERE id = ?", [$transfer['from_warehouse_id']]);
                    $toWarehouse = $db->queryOne("SELECT name FROM warehouses WHERE id = ?", [$transfer['to_warehouse_id']]);
                    
                    $fromName = $fromWarehouse['name'] ?? ('#' . $transfer['from_warehouse_id']);
                    $toName = $toWarehouse['name'] ?? ('#' . $transfer['to_warehouse_id']);
                    
                    // الحصول على عدد العناصر والكمية الإجمالية
                    $itemsInfo = $db->queryOne(
                        "SELECT COUNT(*) as count, COALESCE(SUM(quantity), 0) as total_quantity 
                         FROM warehouse_transfer_items WHERE transfer_id = ?",
                        [$entityId]
                    );
                    $itemsCountValue = $itemsInfo['count'] ?? 0;
                    $totalQuantity = $itemsInfo['total_quantity'] ?? 0;
                    
                    $transferDetails = sprintf(
                        "\n\nالتفاصيل:\nمن: %s\nإلى: %s\nالتاريخ: %s\nعدد العناصر: %d\nالكمية الإجمالية: %.2f",
                        $fromName,
                        $toName,
                        $transfer['transfer_date'] ?? date('Y-m-d'),
                        $itemsCountValue,
                        $totalQuantity
                    );
                }
            } catch (Exception $e) {
                error_log('Error getting transfer details for notification: ' . $e->getMessage());
            }
            
            $notificationTitle = 'طلب موافقة نقل منتجات بين المخازن';
            $notificationMessage = "تم استلام طلب موافقة جديد لنقل منتجات بين المخازن{$transferNumber}.{$transferDetails}\n\nيرجى مراجعة الطلب والموافقة عليه.";
        } else {
            $notificationTitle = 'طلب موافقة جديد';
            $notificationMessage = "تم طلب موافقة على {$entityName} من نوع {$type}";
        }
        
        notifyManagers(
            $notificationTitle,
            $notificationMessage,
            'approval',
            "dashboard/manager.php?page=approvals&id={$result['insert_id']}"
        );
        
        // تسجيل سجل التدقيق
        logAudit($requestedBy, 'request_approval', $type, $entityId, null, ['approval_id' => $result['insert_id']]);
        
        return ['success' => true, 'approval_id' => $result['insert_id']];
        
    } catch (Exception $e) {
        error_log("Approval Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في طلب الموافقة'];
    }
}

/**
 * الموافقة على طلب
 */
function approveRequest($approvalId, $approvedBy, $notes = null) {
    try {
        $db = db();
        
        // الحصول على بيانات الموافقة
        $approval = $db->queryOne(
            "SELECT * FROM approvals WHERE id = ? AND status = 'pending'",
            [$approvalId]
        );
        $entityColumn = getApprovalsEntityColumn();
        
        if (!$approval) {
            return ['success' => false, 'message' => 'الموافقة غير موجودة أو تمت الموافقة عليها مسبقاً'];
        }

        $entityIdentifier = $approval[$entityColumn] ?? null;
        if ($entityIdentifier === null) {
            return ['success' => false, 'message' => 'تعذر تحديد الكيان المرتبط بطلب الموافقة.'];
        }
        
        // تحديث حالة الموافقة
        // التحقق من اسم عمود الملاحظات
        $notesColumn = 'notes';
        $columns = $db->query("SHOW COLUMNS FROM approvals") ?? [];
        $hasNotesColumn = false;
        $hasApprovalNotesColumn = false;
        
        foreach ($columns as $column) {
            $fieldName = $column['Field'] ?? '';
            if ($fieldName === 'notes') {
                $hasNotesColumn = true;
            } elseif ($fieldName === 'approval_notes') {
                $hasApprovalNotesColumn = true;
                $notesColumn = 'approval_notes';
            }
        }
        
        // بناء استعلام التحديث بناءً على الأعمدة المتاحة
        if ($hasNotesColumn || $hasApprovalNotesColumn) {
            $db->execute(
                "UPDATE approvals SET status = 'approved', approved_by = ?, {$notesColumn} = ? 
                 WHERE id = ?",
                [$approvedBy, $notes, $approvalId]
            );
        } else {
            // إذا لم يكن هناك عمود ملاحظات، تحديث بدون ملاحظات
            $db->execute(
                "UPDATE approvals SET status = 'approved', approved_by = ? 
                 WHERE id = ?",
                [$approvedBy, $approvalId]
            );
        }
        
        // تحديث حالة الكيان
        updateEntityStatus($approval['type'], $entityIdentifier, 'approved', $approvedBy);
        
        // بناء رسالة الإشعار مع تفاصيل المنتجات المنقولة
        $notificationMessage = "تمت الموافقة على طلبك من نوع {$approval['type']}";
        
        // إذا كان الطلب نقل منتجات، أضف تفاصيل المنتجات المنقولة
        if ($approval['type'] === 'warehouse_transfer' && !empty($_SESSION['warehouse_transfer_products'])) {
            $products = $_SESSION['warehouse_transfer_products'];
            unset($_SESSION['warehouse_transfer_products']); // حذف بعد الاستخدام
            
            if (!empty($products)) {
                $notificationMessage .= "\n\nالمنتجات المنقولة:\n";
                foreach ($products as $product) {
                    $batchInfo = !empty($product['batch_number']) ? " - تشغيلة {$product['batch_number']}" : '';
                    $notificationMessage .= "• {$product['name']}{$batchInfo}: " . number_format($product['quantity'], 2) . "\n";
                }
            }
        }
        
        // إرسال إشعار للمستخدم الذي طلب الموافقة
        require_once __DIR__ . '/notifications.php';
        createNotification(
            $approval['requested_by'],
            'تمت الموافقة',
            $notificationMessage,
            'success',
            getEntityLink($approval['type'], $entityIdentifier)
        );
        
        // تسجيل سجل التدقيق
        logAudit($approvedBy, 'approve', 'approval', $approvalId, 'pending', 'approved');
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Approval Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في الموافقة'];
    }
}

/**
 * رفض طلب
 */
function rejectRequest($approvalId, $approvedBy, $rejectionReason) {
    try {
        $db = db();
        
        // الحصول على بيانات الموافقة
        $approval = $db->queryOne(
            "SELECT * FROM approvals WHERE id = ? AND status = 'pending'",
            [$approvalId]
        );
        $entityColumn = getApprovalsEntityColumn();
        
        if (!$approval) {
            return ['success' => false, 'message' => 'الموافقة غير موجودة أو تمت الموافقة عليها مسبقاً'];
        }

        $entityIdentifier = $approval[$entityColumn] ?? null;
        if ($entityIdentifier === null) {
            return ['success' => false, 'message' => 'تعذر تحديد الكيان المرتبط بطلب الموافقة.'];
        }
        
        // تحديث حالة الموافقة
        $db->execute(
            "UPDATE approvals SET status = 'rejected', approved_by = ?, rejection_reason = ? 
             WHERE id = ?",
            [$approvedBy, $rejectionReason, $approvalId]
        );
        
        // تحديث حالة الكيان
        try {
            updateEntityStatus($approval['type'], $entityIdentifier, 'rejected', $approvedBy);
        } catch (Exception $e) {
            // إرجاع حالة الرفض إلى pending عند الفشل
            $db->execute(
                "UPDATE approvals SET status = 'pending', approved_by = NULL, rejection_reason = NULL WHERE id = ?",
                [$approvalId]
            );
            error_log("Failed to update entity status during rejection: " . $e->getMessage());
            
            // التحقق من قاعدة البيانات للتأكد من أن الكيان لم يتم رفضه بالفعل
            if ($approval['type'] === 'warehouse_transfer') {
                $verifyTransfer = $db->queryOne(
                    "SELECT status FROM warehouse_transfers WHERE id = ?",
                    [$entityIdentifier]
                );
                if ($verifyTransfer && $verifyTransfer['status'] === 'rejected') {
                    // الطلب تم رفضه بالفعل - نجاح
                    error_log("Warning: Transfer was rejected (ID: $entityIdentifier) but updateEntityStatus failed. Details: " . $e->getMessage());
                } else {
                    return ['success' => false, 'message' => 'حدث خطأ أثناء رفض الطلب: ' . $e->getMessage()];
                }
            } else {
                return ['success' => false, 'message' => 'حدث خطأ أثناء رفض الطلب: ' . $e->getMessage()];
            }
        }
        
        // إرسال إشعار للمستخدم الذي طلب الموافقة
        try {
            require_once __DIR__ . '/notifications.php';
            createNotification(
                $approval['requested_by'],
                'تم رفض الطلب',
                "تم رفض طلبك من نوع {$approval['type']}. السبب: {$rejectionReason}",
                'error',
                getEntityLink($approval['type'], $entityIdentifier)
            );
        } catch (Exception $notifException) {
            // لا نسمح لفشل الإشعار بإلغاء نجاح الرفض
            error_log('Notification creation exception during rejection: ' . $notifException->getMessage());
        }
        
        // تسجيل سجل التدقيق
        try {
            logAudit($approvedBy, 'reject', 'approval', $approvalId, 'pending', 'rejected');
        } catch (Exception $auditException) {
            // لا نسمح لفشل التدقيق بإلغاء نجاح الرفض
            error_log('Audit log exception during rejection: ' . $auditException->getMessage());
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Approval Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في الرفض'];
    }
}

/**
 * تحديث حالة الكيان
 */
function updateEntityStatus($type, $entityId, $status, $approvedBy) {
    $db = db();
    
    switch ($type) {
        case 'financial':
            $db->execute(
                "UPDATE financial_transactions SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            break;
            
        case 'sales':
            $db->execute(
                "UPDATE sales SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            break;
            
        case 'production':
            $db->execute(
                "UPDATE production SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            break;
            
        case 'collection':
            $db->execute(
                "UPDATE collections SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            break;
            
        case 'salary':
            $db->execute(
                "UPDATE salaries SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            break;
            
        case 'salary_modification':
            // عند الموافقة على تعديل الراتب
            if ($status === 'approved') {
                // entityId هنا هو approval_id وليس salary_id
                $approval = $db->queryOne("SELECT * FROM approvals WHERE id = ?", [$entityId]);
                if ($approval) {
                    $entityColumnName = getApprovalsEntityColumn();
                    $salaryId = $approval[$entityColumnName] ?? null;
                    if ($salaryId === null) {
                        break;
                    }
                    
                    // استخراج بيانات التعديل من notes
                    $modificationData = null;
                    if ($approval['notes']) {
                        // محاولة استخراج JSON من notes بعد [DATA]:
                        if (preg_match('/\[DATA\]:(.+)/s', $approval['notes'], $matches)) {
                            $modificationData = json_decode(trim($matches[1]), true);
                        } else {
                            // محاولة بديلة: استخراج من notes في جدول salaries
                            $salaryNote = $db->queryOne("SELECT notes FROM salaries WHERE id = ?", [$salaryId]);
                            if ($salaryNote && preg_match('/\[تعديل معلق\]:\s*(.+)/s', $salaryNote['notes'], $matches)) {
                                $modificationData = json_decode(trim($matches[1]), true);
                            }
                        }
                    }
                    
                    if ($modificationData) {
                        $bonus = $modificationData['bonus'] ?? 0;
                        $deductions = $modificationData['deductions'] ?? 0;
                        $notes = $modificationData['notes'] ?? '';
                        
                        // الحصول على الراتب الحالي
                        $salary = $db->queryOne("SELECT * FROM salaries WHERE id = ?", [$salaryId]);
                        if ($salary) {
                            $newTotal = $salary['base_amount'] + $bonus - $deductions;
                            
                            // تحديث الراتب مع إزالة التعديل المعلق من notes
                            $currentNotes = $salary['notes'] ?? '';
                            $cleanedNotes = preg_replace('/\[تعديل معلق\]:\s*[^\n]+/s', '', $currentNotes);
                            $cleanedNotes = trim($cleanedNotes);
                            
                            $db->execute(
                                "UPDATE salaries SET 
                                    bonus = ?,
                                    deductions = ?,
                                    total_amount = ?,
                                    notes = CONCAT(?, '\n[تم التعديل]: ', ?),
                                    updated_at = NOW()
                                 WHERE id = ?",
                                [$bonus, $deductions, $newTotal, $cleanedNotes, $notes, $salaryId]
                            );
                            
                            // إرسال إشعار للمستخدم
                            require_once __DIR__ . '/notifications.php';
                            createNotification(
                                $salary['user_id'],
                                'تم تعديل راتبك',
                                "تم الموافقة على تعديل راتبك. مكافأة: " . number_format($bonus, 2) . " جنيه, خصومات: " . number_format($deductions, 2) . " جنيه",
                                'info',
                                null,
                                false
                            );
                        }
                    }
                }
            }
            break;

        case 'warehouse_transfer':
            require_once __DIR__ . '/vehicle_inventory.php';
            if ($status === 'approved') {
                $result = approveWarehouseTransfer($entityId, $approvedBy);
                if (!($result['success'] ?? false)) {
                    throw new Exception($result['message'] ?? 'تعذر الموافقة على طلب النقل.');
                }
                // حفظ معلومات المنتجات المنقولة للاستخدام في الإشعار
                $_SESSION['warehouse_transfer_products'] = $result['transferred_products'] ?? [];
            } elseif ($status === 'rejected') {
                $entityColumnName = getApprovalsEntityColumn();
                $approvalRow = $db->queryOne(
                    "SELECT rejection_reason FROM approvals WHERE type = 'warehouse_transfer' AND `{$entityColumnName}` = ? ORDER BY updated_at DESC LIMIT 1",
                    [$entityId]
                );
                $reason = $approvalRow['rejection_reason'] ?? 'تم رفض طلب النقل.';
                $result = rejectWarehouseTransfer($entityId, $reason, $approvedBy);
                if (!($result['success'] ?? false)) {
                    throw new Exception($result['message'] ?? 'تعذر رفض طلب النقل.');
                }
            }
            break;

        case 'invoice_return_company':
            // الحصول على بيانات المرتجع
            $return = $db->queryOne(
                "SELECT * FROM returns WHERE id = ?",
                [$entityId]
            );
            
            if (!$return) {
                throw new Exception('المرتجع غير موجود');
            }
            
            // تحديث حالة المرتجع
            $db->execute(
                "UPDATE returns SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            
            // إذا تمت الموافقة، إرجاع المنتجات إلى مخزن سيارة المندوب
            if ($status === 'approved' && !empty($return['sales_rep_id'])) {
                require_once __DIR__ . '/vehicle_inventory.php';
                require_once __DIR__ . '/inventory_movements.php';
                
                $salesRepId = (int)$return['sales_rep_id'];
                $returnNumber = $return['return_number'] ?? 'RET-' . $entityId;
                
                // الحصول على vehicle_id من sales_rep_id
                $vehicle = $db->queryOne(
                    "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                    [$salesRepId]
                );
                
                if (!$vehicle) {
                    throw new Exception('لا يوجد سيارة نشطة مرتبطة بهذا المندوب');
                }
                
                $vehicleId = (int)$vehicle['id'];
                
                // الحصول على أو إنشاء مخزن السيارة
                $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                if (!$vehicleWarehouse) {
                    $createWarehouse = createVehicleWarehouse($vehicleId);
                    if (empty($createWarehouse['success'])) {
                        throw new Exception('تعذر تجهيز مخزن السيارة لاستلام المرتجع');
                    }
                    $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                }
                
                $warehouseId = $vehicleWarehouse['id'] ?? null;
                if (!$warehouseId) {
                    throw new Exception('تعذر تحديد مخزن السيارة');
                }
                
                // الحصول على عناصر المرتجع
                $returnItems = $db->query(
                    "SELECT * FROM return_items WHERE return_id = ?",
                    [$entityId]
                );
                
                if (empty($returnItems)) {
                    throw new Exception('لا توجد منتجات في المرتجع');
                }
                
                // التحقق من وجود حركات مخزون سابقة لهذا المرتجع لتجنب الإضافة المكررة
                $existingMovements = $db->query(
                    "SELECT product_id, SUM(quantity) as total_quantity 
                     FROM inventory_movements 
                     WHERE reference_type = 'invoice_return' AND reference_id = ? AND movement_type = 'in'
                     GROUP BY product_id",
                    [$entityId]
                );
                
                $alreadyAdded = [];
                foreach ($existingMovements as $movement) {
                    $alreadyAdded[(int)$movement['product_id']] = (float)$movement['total_quantity'];
                }
                
                // إضافة كل منتج إلى مخزن السيارة (فقط إذا لم يُضف من قبل)
                foreach ($returnItems as $item) {
                    $productId = (int)$item['product_id'];
                    $quantity = (float)$item['quantity'];
                    
                    // التحقق من أن المنتج لم يُضف بالفعل
                    $alreadyAddedQuantity = $alreadyAdded[$productId] ?? 0;
                    if ($alreadyAddedQuantity >= $quantity - 0.0001) {
                        // المنتج تم إضافته بالفعل، نتخطاه
                        continue;
                    }
                    
                    // حساب الكمية المتبقية التي يجب إضافتها
                    $remainingQuantity = $quantity - $alreadyAddedQuantity;
                    if ($remainingQuantity <= 0) {
                        continue;
                    }
                    
                    // الحصول على الكمية الحالية في مخزن السيارة
                    $inventoryRow = $db->queryOne(
                        "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                        [$vehicleId, $productId]
                    );
                    
                    $currentQuantity = (float)($inventoryRow['quantity'] ?? 0);
                    $newQuantity = round($currentQuantity + $remainingQuantity, 3);
                    
                    // تحديث مخزون السيارة
                    $updateResult = updateVehicleInventory($vehicleId, $productId, $newQuantity, $approvedBy);
                    if (empty($updateResult['success'])) {
                        throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
                    }
                    
                    // تسجيل حركة المخزون
                    $invoice = $db->queryOne("SELECT invoice_number FROM invoices WHERE id = ?", [$return['invoice_id'] ?? null]);
                    $invoiceNumber = $invoice['invoice_number'] ?? 'غير معروف';
                    
                    $movementResult = recordInventoryMovement(
                        $productId,
                        $warehouseId,
                        'in',
                        $remainingQuantity,
                        'invoice_return',
                        $entityId,
                        'إرجاع فاتورة #' . $invoiceNumber . ' - مرتجع ' . $returnNumber,
                        $approvedBy
                    );
                    
                    if (empty($movementResult['success'])) {
                        throw new Exception($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون');
                    }
                }
            }
            
            // إذا تمت الموافقة وكانت طريقة الإرجاع نقداً، خصم المبلغ من خزنة المندوب
            if ($status === 'approved' && $return['refund_method'] === 'cash' && !empty($return['sales_rep_id']) && !empty($return['refund_amount'])) {
                $salesRepId = (int)$return['sales_rep_id'];
                $refundAmount = (float)$return['refund_amount'];
                $customerId = (int)$return['customer_id'];
                $returnNumber = $return['return_number'] ?? 'RET-' . $entityId;
                
                // التحقق من رصيد خزنة المندوب
                $cashBalance = calculateSalesRepCashBalance($salesRepId);
                if ($cashBalance + 0.0001 < $refundAmount) {
                    throw new Exception('رصيد خزنة المندوب لا يغطي قيمة المرتجع المطلوبة. الرصيد الحالي: ' . number_format($cashBalance, 2));
                }
                
                // خصم المبلغ من خزنة المندوب
                insertNegativeCollection($customerId, $salesRepId, $refundAmount, $returnNumber, $approvedBy);
            }
            
            // إذا تمت الموافقة، خصم 2% من إجمالي مبلغ المرتجع من راتب المندوب
            if ($status === 'approved' && !empty($return['sales_rep_id']) && !empty($return['refund_amount'])) {
                require_once __DIR__ . '/salary_calculator.php';
                
                $salesRepId = (int)$return['sales_rep_id'];
                $refundAmount = (float)$return['refund_amount'];
                $returnNumber = $return['return_number'] ?? 'RET-' . $entityId;
                
                // التحقق من عدم تطبيق الخصم مسبقاً (منع الخصم المكرر)
                $existingDeduction = $db->queryOne(
                    "SELECT id FROM audit_logs 
                     WHERE action = 'return_deduction' 
                     AND entity_type = 'salary' 
                     AND new_value LIKE ?",
                    ['%"return_id":' . $entityId . '%']
                );
                
                if (!empty($existingDeduction)) {
                    // الخصم تم تطبيقه مسبقاً، نتخطى
                    error_log("Return deduction already applied for return ID: {$entityId}");
                } else {
                    // حساب 2% من إجمالي مبلغ المرتجع
                    $deductionAmount = round($refundAmount * 0.02, 2);
                    
                    if ($deductionAmount > 0) {
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
                                error_log('Failed to create salary for return deduction: ' . ($creation['message'] ?? 'unknown error'));
                                throw new Exception('تعذر إنشاء سجل الراتب لخصم المرتجع');
                            }
                            $summary = getSalarySummary($salesRepId, $month, $year);
                            if (!($summary['exists'] ?? false)) {
                                throw new Exception('لم يتم العثور على سجل الراتب بعد إنشائه');
                            }
                        }
                        
                        $salary = $summary['salary'];
                        $salaryId = (int)($salary['id'] ?? 0);
                        
                        if ($salaryId <= 0) {
                            throw new Exception('تعذر تحديد سجل الراتب لخصم المرتجع');
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
                            $params[] = $deductionAmount;
                        }
                        
                        if ($columnMap['total_amount'] !== null) {
                            $updates[] = "{$columnMap['total_amount']} = GREATEST(COALESCE({$columnMap['total_amount']}, 0) - ?, 0)";
                            $params[] = $deductionAmount;
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
                            $deductionNote = "\n[خصم مرتجع]: تم خصم " . number_format($deductionAmount, 2) . " ج.م (2% من مرتجع {$returnNumber} بقيمة " . number_format($refundAmount, 2) . " ج.م)";
                            $newNotes = $currentNotes . $deductionNote;
                            
                            $db->execute(
                                "UPDATE salaries SET notes = ? WHERE id = ?",
                                [$newNotes, $salaryId]
                            );
                            
                            // تسجيل سجل التدقيق
                            logAudit($approvedBy, 'return_deduction', 'salary', $salaryId, null, [
                                'return_id' => $entityId,
                                'return_number' => $returnNumber,
                                'refund_amount' => $refundAmount,
                                'deduction_amount' => $deductionAmount,
                                'sales_rep_id' => $salesRepId
                            ]);
                        }
                    }
                }
            }
            break;
            
        case 'return_request':
            // الحصول على بيانات المرتجع
            $return = $db->queryOne(
                "SELECT * FROM returns WHERE id = ?",
                [$entityId]
            );
            
            if (!$return) {
                throw new Exception('المرتجع غير موجود');
            }
            
            // تحديث حالة المرتجع
            $db->execute(
                "UPDATE returns SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            
            // إذا تمت الموافقة، إرجاع المنتجات إلى مخزن سيارة المندوب
            if ($status === 'approved' && !empty($return['sales_rep_id'])) {
                require_once __DIR__ . '/vehicle_inventory.php';
                require_once __DIR__ . '/inventory_movements.php';
                
                $salesRepId = (int)$return['sales_rep_id'];
                $returnNumber = $return['return_number'] ?? 'RET-' . $entityId;
                
                // الحصول على vehicle_id من sales_rep_id
                $vehicle = $db->queryOne(
                    "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                    [$salesRepId]
                );
                
                if ($vehicle) {
                    $vehicleId = (int)$vehicle['id'];
                    
                    // الحصول على أو إنشاء مخزن السيارة
                    $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                    if (!$vehicleWarehouse) {
                        $createWarehouse = createVehicleWarehouse($vehicleId);
                        if (!empty($createWarehouse['success'])) {
                            $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                        }
                    }
                    
                    $warehouseId = $vehicleWarehouse['id'] ?? null;
                    
                    // الحصول على عناصر المرتجع
                    $returnItems = $db->query(
                        "SELECT * FROM return_items WHERE return_id = ?",
                        [$entityId]
                    );
                    
                    if (!empty($returnItems) && $warehouseId) {
                        // التحقق من وجود حركات مخزون سابقة لهذا المرتجع
                        $existingMovements = $db->query(
                            "SELECT product_id, SUM(quantity) as total_quantity 
                             FROM inventory_movements 
                             WHERE reference_type = 'return' AND reference_id = ? AND movement_type = 'in'
                             GROUP BY product_id",
                            [$entityId]
                        );
                        
                        $alreadyAdded = [];
                        foreach ($existingMovements as $movement) {
                            $alreadyAdded[(int)$movement['product_id']] = (float)$movement['total_quantity'];
                        }
                        
                        // إضافة كل منتج إلى مخزن السيارة
                        foreach ($returnItems as $item) {
                            $productId = (int)$item['product_id'];
                            $quantity = (float)$item['quantity'];
                            
                            // التحقق من أن المنتج لم يُضف بالفعل
                            $alreadyAddedQuantity = $alreadyAdded[$productId] ?? 0;
                            if ($alreadyAddedQuantity >= $quantity - 0.0001) {
                                continue;
                            }
                            
                            $remainingQuantity = $quantity - $alreadyAddedQuantity;
                            if ($remainingQuantity <= 0) {
                                continue;
                            }
                            
                            // الحصول على الكمية الحالية في مخزن السيارة
                            $inventoryRow = $db->queryOne(
                                "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                                [$vehicleId, $productId]
                            );
                            
                            $currentQuantity = (float)($inventoryRow['quantity'] ?? 0);
                            $newQuantity = round($currentQuantity + $remainingQuantity, 3);
                            
                            // تحديث مخزون السيارة
                            $updateResult = updateVehicleInventory($vehicleId, $productId, $newQuantity, $approvedBy);
                            if (empty($updateResult['success'])) {
                                throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
                            }
                            
                            // تسجيل حركة المخزون
                            recordInventoryMovement(
                                $productId,
                                $warehouseId,
                                'in',
                                $remainingQuantity,
                                'return',
                                $entityId,
                                'إرجاع مرتجع ' . $returnNumber . ' إلى مخزن سيارة المندوب',
                                $approvedBy
                            );
                        }
                    }
                }
            }
            break;
            
        case 'exchange_request':
            // الحصول على بيانات الاستبدال
            $exchange = $db->queryOne(
                "SELECT * FROM exchanges WHERE id = ?",
                [$entityId]
            );
            
            if (!$exchange) {
                throw new Exception('الاستبدال غير موجود');
            }
            
            // تحديث حالة الاستبدال
            $db->execute(
                "UPDATE exchanges SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?",
                [$status, $approvedBy, $entityId]
            );
            
            // إذا تمت الموافقة، معالجة المخزون والرصيد
            if ($status === 'approved') {
                require_once __DIR__ . '/return_processor.php';
                require_once __DIR__ . '/vehicle_inventory.php';
                require_once __DIR__ . '/inventory_movements.php';
                
                $salesRepId = (int)($exchange['sales_rep_id'] ?? 0);
                $customerId = (int)$exchange['customer_id'];
                $exchangeNumber = $exchange['exchange_number'] ?? 'EXC-' . $entityId;
                
                // الحصول على المنتجات المرجعة
                $returnItems = $db->query(
                    "SELECT * FROM exchange_return_items WHERE exchange_id = ?",
                    [$entityId]
                );
                
                // الحصول على المنتجات البديلة
                $replacementItems = $db->query(
                    "SELECT * FROM exchange_new_items WHERE exchange_id = ?",
                    [$entityId]
                );
                
                // إرجاع المنتجات القديمة إلى مخزن السيارة
                if ($salesRepId > 0 && !empty($returnItems)) {
                    $vehicle = $db->queryOne(
                        "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                        [$salesRepId]
                    );
                    
                    if ($vehicle) {
                        $vehicleId = (int)$vehicle['id'];
                        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                        
                        if ($vehicleWarehouse) {
                            $warehouseId = $vehicleWarehouse['id'];
                            
                            foreach ($returnItems as $item) {
                                $productId = (int)$item['product_id'];
                                $quantity = (float)$item['quantity'];
                                
                                // الحصول على الكمية الحالية في مخزن السيارة
                                $inventoryRow = $db->queryOne(
                                    "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                                    [$vehicleId, $productId]
                                );
                                
                                $currentQuantity = (float)($inventoryRow['quantity'] ?? 0);
                                $newQuantity = round($currentQuantity + $quantity, 3);
                                
                                // تحديث مخزون السيارة
                                $updateResult = updateVehicleInventory($vehicleId, $productId, $newQuantity, $approvedBy);
                                if (empty($updateResult['success'])) {
                                    throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
                                }
                                
                                // تسجيل حركة المخزون
                                recordInventoryMovement(
                                    $productId,
                                    $warehouseId,
                                    'in',
                                    $quantity,
                                    'exchange',
                                    $entityId,
                                    'إرجاع من استبدال ' . $exchangeNumber,
                                    $approvedBy
                                );
                            }
                        }
                    }
                }
                
                // خروج المنتجات الجديدة من مخزن السيارة
                if ($salesRepId > 0 && !empty($replacementItems)) {
                    $vehicle = $db->queryOne(
                        "SELECT id FROM vehicles WHERE driver_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1",
                        [$salesRepId]
                    );
                    
                    if ($vehicle) {
                        $vehicleId = (int)$vehicle['id'];
                        $vehicleWarehouse = getVehicleWarehouse($vehicleId);
                        
                        if ($vehicleWarehouse) {
                            $warehouseId = $vehicleWarehouse['id'];
                            
                            foreach ($replacementItems as $item) {
                                $productId = (int)$item['product_id'];
                                $quantity = (float)$item['quantity'];
                                
                                // التحقق من توفر الكمية في مخزن السيارة
                                $inventoryRow = $db->queryOne(
                                    "SELECT id, quantity FROM vehicle_inventory WHERE vehicle_id = ? AND product_id = ? FOR UPDATE",
                                    [$vehicleId, $productId]
                                );
                                
                                if (!$inventoryRow) {
                                    throw new Exception("المنتج غير موجود في مخزن السيارة");
                                }
                                
                                $currentQuantity = (float)$inventoryRow['quantity'];
                                if ($currentQuantity < $quantity) {
                                    throw new Exception("الكمية المتاحة ({$currentQuantity}) أقل من المطلوب ({$quantity})");
                                }
                                
                                $newQuantity = round($currentQuantity - $quantity, 3);
                                
                                // تحديث مخزون السيارة
                                $updateResult = updateVehicleInventory($vehicleId, $productId, $newQuantity, $approvedBy);
                                if (empty($updateResult['success'])) {
                                    throw new Exception($updateResult['message'] ?? 'تعذر تحديث مخزون السيارة');
                                }
                                
                                // تسجيل حركة المخزون
                                recordInventoryMovement(
                                    $productId,
                                    $warehouseId,
                                    'out',
                                    $quantity,
                                    'exchange',
                                    $entityId,
                                    'استبدال ' . $exchangeNumber,
                                    $approvedBy
                                );
                            }
                        }
                    }
                }
                
                // تحديث رصيد العميل
                $difference = (float)$exchange['difference_amount'];
                if (abs($difference) >= 0.01) {
                    $customer = $db->queryOne("SELECT balance FROM customers WHERE id = ? FOR UPDATE", [$customerId]);
                    $customerBalance = (float)($customer['balance'] ?? 0);
                    
                    if ($difference < 0) {
                        // المنتج البديل أرخص - إضافة للرصيد الدائن
                        $newBalance = round($customerBalance - abs($difference), 2);
                    } else {
                        // المنتج البديل أغلى - إضافة للدين
                        $newBalance = round($customerBalance + $difference, 2);
                    }
                    
                    $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                }
            }
            break;
    }
}

/**
 * الحصول على اسم الكيان
 */
function getEntityName($type, $entityId) {
    $db = db();
    
    switch ($type) {
        case 'financial':
            $entity = $db->queryOne("SELECT description FROM financial_transactions WHERE id = ?", [$entityId]);
            return $entity['description'] ?? "معاملة مالية #{$entityId}";
            
        case 'sales':
            $entity = $db->queryOne("SELECT s.*, c.name as customer_name FROM sales s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?", [$entityId]);
            return $entity ? "مبيعة #{$entityId} - {$entity['customer_name']}" : "مبيعة #{$entityId}";
            
        case 'production':
            $entity = $db->queryOne("SELECT p.*, pr.name as product_name FROM production p LEFT JOIN products pr ON p.product_id = pr.id WHERE p.id = ?", [$entityId]);
            return $entity ? "إنتاج #{$entityId} - {$entity['product_name']}" : "إنتاج #{$entityId}";
            
        case 'collection':
            $entity = $db->queryOne("SELECT c.*, cu.name as customer_name FROM collections c LEFT JOIN customers cu ON c.customer_id = cu.id WHERE c.id = ?", [$entityId]);
            return $entity ? "تحصيل #{$entityId} - {$entity['customer_name']}" : "تحصيل #{$entityId}";
            
        case 'salary':
            $entity = $db->queryOne("SELECT s.*, u.full_name FROM salaries s LEFT JOIN users u ON s.user_id = u.id WHERE s.id = ?", [$entityId]);
            return $entity ? "راتب #{$entityId} - {$entity['full_name']}" : "راتب #{$entityId}";

        case 'warehouse_transfer':
            $entity = $db->queryOne("SELECT transfer_number FROM warehouse_transfers WHERE id = ?", [$entityId]);
            return $entity ? "طلب نقل مخزني {$entity['transfer_number']}" : "طلب نقل مخزني #{$entityId}";

        case 'return_request':
            $entity = $db->queryOne(
                "SELECT r.return_number, i.invoice_number, c.name as customer_name
                 FROM returns r
                 LEFT JOIN invoices i ON r.invoice_id = i.id
                 LEFT JOIN customers c ON r.customer_id = c.id
                 WHERE r.id = ?",
                [$entityId]
            );
            if ($entity) {
                $parts = [];
                if (!empty($entity['return_number'])) {
                    $parts[] = "مرتجع {$entity['return_number']}";
                }
                if (!empty($entity['invoice_number'])) {
                    $parts[] = "فاتورة {$entity['invoice_number']}";
                }
                if (!empty($entity['customer_name'])) {
                    $parts[] = $entity['customer_name'];
                }
                return implode(' - ', $parts);
            }
            return "مرتجع #{$entityId}";
            
        case 'exchange_request':
            $entity = $db->queryOne(
                "SELECT e.exchange_number, c.name as customer_name
                 FROM exchanges e
                 LEFT JOIN customers c ON e.customer_id = c.id
                 WHERE e.id = ?",
                [$entityId]
            );
            if ($entity) {
                $parts = [];
                if (!empty($entity['exchange_number'])) {
                    $parts[] = "استبدال {$entity['exchange_number']}";
                }
                if (!empty($entity['customer_name'])) {
                    $parts[] = $entity['customer_name'];
                }
                return implode(' - ', $parts);
            }
            return "استبدال #{$entityId}";
            
        case 'invoice_return_company':
            $entity = $db->queryOne(
                "SELECT r.return_number, i.invoice_number, c.name as customer_name
                 FROM returns r
                 LEFT JOIN invoices i ON r.invoice_id = i.id
                 LEFT JOIN customers c ON r.customer_id = c.id
                 WHERE r.id = ?",
                [$entityId]
            );
            if ($entity) {
                $parts = [];
                if (!empty($entity['return_number'])) {
                    $parts[] = "مرتجع {$entity['return_number']}";
                }
                if (!empty($entity['invoice_number'])) {
                    $parts[] = "فاتورة {$entity['invoice_number']}";
                }
                if (!empty($entity['customer_name'])) {
                    $parts[] = $entity['customer_name'];
                }
                return implode(' - ', $parts);
            }
            return "مرتجع فاتورة #{$entityId}";
            
        default:
            return "كيان #{$entityId}";
    }
}

/**
 * الحصول على رابط الكيان
 */
function getEntityLink($type, $entityId) {
    $baseUrl = '/dashboard/';
    
    switch ($type) {
        case 'financial':
            return $baseUrl . 'accountant.php?page=financial&id=' . $entityId;
            
        case 'sales':
            return $baseUrl . 'sales.php?page=sales_collections&id=' . $entityId;
            
        case 'production':
            return $baseUrl . 'production.php?page=production&id=' . $entityId;
            
        case 'collection':
            return $baseUrl . 'accountant.php?page=collections&id=' . $entityId;
            
        case 'salary':
            return $baseUrl . 'accountant.php?page=salaries&id=' . $entityId;

        case 'warehouse_transfer':
            return $baseUrl . 'manager.php?page=warehouse_transfers&id=' . $entityId;

        case 'invoice_return_company':
            return $baseUrl . 'manager.php?page=returns&id=' . $entityId;
            
        default:
            return $baseUrl . 'manager.php?page=approvals';
    }
}

/**
 * الحصول على الموافقات المعلقة
 */
function getPendingApprovals($limit = 50, $offset = 0) {
    $db = db();
    
    return $db->query(
        "SELECT a.*, u1.username as requested_by_name, u2.username as approved_by_name,
                u1.full_name as requested_by_full_name
         FROM approvals a
         LEFT JOIN users u1 ON a.requested_by = u1.id
         LEFT JOIN users u2 ON a.approved_by = u2.id
         WHERE a.status = 'pending'
         ORDER BY a.created_at DESC
         LIMIT ? OFFSET ?",
        [$limit, $offset]
    );
}

/**
 * الحصول على عدد الموافقات المعلقة
 */
function getPendingApprovalsCount() {
    $db = db();
    
    $result = $db->queryOne("SELECT COUNT(*) as count FROM approvals WHERE status = 'pending'");
    return $result['count'] ?? 0;
}

/**
 * الحصول على موافقة واحدة
 */
function getApproval($approvalId) {
    $db = db();
    
    return $db->queryOne(
        "SELECT a.*, u1.username as requested_by_name, u1.full_name as requested_by_full_name,
                u2.username as approved_by_name, u2.full_name as approved_by_full_name
         FROM approvals a
         LEFT JOIN users u1 ON a.requested_by = u1.id
         LEFT JOIN users u2 ON a.approved_by = u2.id
         WHERE a.id = ?",
        [$approvalId]
    );
}

/**
 * حساب رصيد خزنة المندوب
 */
function calculateSalesRepCashBalance($salesRepId) {
    $db = db();
    $cashBalance = 0.0;

    $invoicesExists = $db->queryOne("SHOW TABLES LIKE 'invoices'");
    $collectionsExists = $db->queryOne("SHOW TABLES LIKE 'collections'");

    $totalCollections = 0.0;
    if (!empty($collectionsExists)) {
        $collectionsResult = $db->queryOne(
            "SELECT COALESCE(SUM(amount), 0) as total_collections
             FROM collections
             WHERE collected_by = ?",
            [$salesRepId]
        );
        $totalCollections = (float)($collectionsResult['total_collections'] ?? 0);
    }

    $fullyPaidSales = 0.0;
    if (!empty($invoicesExists)) {
        $fullyPaidResult = $db->queryOne(
            "SELECT COALESCE(SUM(total_amount), 0) as fully_paid
             FROM invoices
             WHERE sales_rep_id = ?
               AND status = 'paid'
               AND paid_amount >= total_amount",
            [$salesRepId]
        );
        $fullyPaidSales = (float)($fullyPaidResult['fully_paid'] ?? 0);
    }

    return $totalCollections + $fullyPaidSales;
}

/**
 * إدراج تحصيل سالب لخصم المبلغ من خزنة المندوب
 */
function insertNegativeCollection($customerId, $salesRepId, $amount, $returnNumber, $approvedBy) {
    $db = db();
    $columns = $db->query("SHOW COLUMNS FROM collections") ?? [];
    $columnNames = [];
    foreach ($columns as $column) {
        if (!empty($column['Field'])) {
            $columnNames[] = $column['Field'];
        }
    }

    $hasStatus = in_array('status', $columnNames, true);
    $hasApprovedBy = in_array('approved_by', $columnNames, true);
    $hasApprovedAt = in_array('approved_at', $columnNames, true);

    $fields = [];
    $placeholders = [];
    $values = [];

    $baseData = [
        'customer_id' => $customerId,
        'amount' => $amount * -1,
        'date' => date('Y-m-d'),
        'payment_method' => 'cash',
        'reference_number' => 'REFUND-' . $returnNumber,
        'notes' => 'صرف نقدي - مرتجع فاتورة ' . $returnNumber,
        'collected_by' => $salesRepId,
    ];

    foreach ($baseData as $column => $value) {
        $fields[] = $column;
        $placeholders[] = '?';
        $values[] = $value;
    }

    if ($hasStatus) {
        $fields[] = 'status';
        $placeholders[] = '?';
        $values[] = 'approved';
    }

    if ($hasApprovedBy) {
        $fields[] = 'approved_by';
        $placeholders[] = '?';
        $values[] = $approvedBy;
    }

    if ($hasApprovedAt) {
        $fields[] = 'approved_at';
        $placeholders[] = 'NOW()';
    }

    $sql = "INSERT INTO collections (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";

    $db->execute($sql, $values);
}

