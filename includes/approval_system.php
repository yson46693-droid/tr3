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

/**
 * طلب موافقة
 */
function requestApproval($type, $entityId, $requestedBy, $notes = null) {
    try {
        $db = db();
        
        // التحقق من وجود موافقة معلقة
        $existing = $db->queryOne(
            "SELECT id FROM approvals 
             WHERE type = ? AND entity_id = ? AND status = 'pending'",
            [$type, $entityId]
        );
        
        if ($existing) {
            return ['success' => false, 'message' => 'يوجد موافقة معلقة بالفعل'];
        }
        
        // إنشاء موافقة جديدة
        $sql = "INSERT INTO approvals (type, entity_id, requested_by, status, notes) 
                VALUES (?, ?, ?, 'pending', ?)";
        
        $result = $db->execute($sql, [
            $type,
            $entityId,
            $requestedBy,
            $notes
        ]);
        
        // إرسال إشعار للمديرين
        $entityName = getEntityName($type, $entityId);
        notifyManagers(
            'طلب موافقة جديد',
            "تم طلب موافقة على {$entityName} من نوع {$type}",
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
        
        if (!$approval) {
            return ['success' => false, 'message' => 'الموافقة غير موجودة أو تمت الموافقة عليها مسبقاً'];
        }
        
        // تحديث حالة الموافقة
        $db->execute(
            "UPDATE approvals SET status = 'approved', approved_by = ?, notes = ?, updated_at = NOW() 
             WHERE id = ?",
            [$approvedBy, $notes, $approvalId]
        );
        
        // تحديث حالة الكيان
        updateEntityStatus($approval['type'], $approval['entity_id'], 'approved', $approvedBy);
        
        // إرسال إشعار للمستخدم الذي طلب الموافقة
        createNotification(
            $approval['requested_by'],
            'تمت الموافقة',
            "تمت الموافقة على طلبك من نوع {$approval['type']}",
            'success',
            getEntityLink($approval['type'], $approval['entity_id'])
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
        
        if (!$approval) {
            return ['success' => false, 'message' => 'الموافقة غير موجودة أو تمت الموافقة عليها مسبقاً'];
        }
        
        // تحديث حالة الموافقة
        $db->execute(
            "UPDATE approvals SET status = 'rejected', approved_by = ?, rejection_reason = ?, updated_at = NOW() 
             WHERE id = ?",
            [$approvedBy, $rejectionReason, $approvalId]
        );
        
        // تحديث حالة الكيان
        updateEntityStatus($approval['type'], $approval['entity_id'], 'rejected', $approvedBy);
        
        // إرسال إشعار للمستخدم الذي طلب الموافقة
        createNotification(
            $approval['requested_by'],
            'تم رفض الطلب',
            "تم رفض طلبك من نوع {$approval['type']}. السبب: {$rejectionReason}",
            'error',
            getEntityLink($approval['type'], $approval['entity_id'])
        );
        
        // تسجيل سجل التدقيق
        logAudit($approvedBy, 'reject', 'approval', $approvalId, 'pending', 'rejected');
        
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
                    $salaryId = $approval['entity_id'];
                    
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
            } elseif ($status === 'rejected') {
                $approvalRow = $db->queryOne(
                    "SELECT rejection_reason FROM approvals WHERE type = 'warehouse_transfer' AND entity_id = ? ORDER BY updated_at DESC LIMIT 1",
                    [$entityId]
                );
                $reason = $approvalRow['rejection_reason'] ?? 'تم رفض طلب النقل.';
                $result = rejectWarehouseTransfer($entityId, $reason, $approvedBy);
                if (!($result['success'] ?? false)) {
                    throw new Exception($result['message'] ?? 'تعذر رفض طلب النقل.');
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

