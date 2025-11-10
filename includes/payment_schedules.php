<?php
/**
 * نظام الجداول الزمنية للتحصيل
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';

// دالة مساعدة لتنسيق العملة (إذا لم تكن موجودة)
if (!function_exists('formatCurrency')) {
    require_once __DIR__ . '/config.php';
}

/**
 * إنشاء جدول زمني للتحصيل
 */
function createPaymentSchedule($saleId, $customerId, $salesRepId, $totalAmount, $installments, 
                                $firstDueDate, $intervalDays = 30, $createdBy = null) {
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
        
        $installmentAmount = $totalAmount / $installments;
        $schedules = [];
        
        for ($i = 1; $i <= $installments; $i++) {
            $dueDate = date('Y-m-d', strtotime($firstDueDate . " + " . (($i - 1) * $intervalDays) . " days"));
            
            $status = $i === 1 ? 'pending' : 'pending';
            
            $db->execute(
                "INSERT INTO payment_schedules 
                (sale_id, customer_id, sales_rep_id, amount, due_date, installment_number, 
                 total_installments, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $saleId,
                    $customerId,
                    $salesRepId,
                    $installmentAmount,
                    $dueDate,
                    $i,
                    $installments,
                    $status,
                    $createdBy
                ]
            );
            
            $schedules[] = [
                'installment_number' => $i,
                'amount' => $installmentAmount,
                'due_date' => $dueDate
            ];
        }
        
        logAudit($createdBy, 'create_payment_schedule', 'payment_schedule', $saleId, null, [
            'installments' => $installments,
            'total_amount' => $totalAmount
        ]);
        
        return ['success' => true, 'schedules' => $schedules];
        
    } catch (Exception $e) {
        error_log("Payment Schedule Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء الجدول الزمني'];
    }
}

/**
 * الحصول على الجداول الزمنية
 */
function getPaymentSchedules($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    // التحقق من وجود عمود sale_number في جدول sales
    $saleNumberColumnCheck = $db->queryOne("SHOW COLUMNS FROM sales LIKE 'sale_number'");
    $hasSaleNumberColumn = !empty($saleNumberColumnCheck);
    
    if ($hasSaleNumberColumn) {
        $sql = "SELECT ps.*, s.sale_number, c.name as customer_name, 
                       u.full_name as sales_rep_name, u.username as sales_rep_username
                FROM payment_schedules ps
                LEFT JOIN sales s ON ps.sale_id = s.id
                LEFT JOIN customers c ON ps.customer_id = c.id
                LEFT JOIN users u ON ps.sales_rep_id = u.id
                WHERE 1=1";
    } else {
        $sql = "SELECT ps.*, s.id as sale_number, c.name as customer_name, 
                       u.full_name as sales_rep_name, u.username as sales_rep_username
                FROM payment_schedules ps
                LEFT JOIN sales s ON ps.sale_id = s.id
                LEFT JOIN customers c ON ps.customer_id = c.id
                LEFT JOIN users u ON ps.sales_rep_id = u.id
                WHERE 1=1";
    }
    
    $params = [];
    
    if (!empty($filters['sales_rep_id'])) {
        $sql .= " AND ps.sales_rep_id = ?";
        $params[] = $filters['sales_rep_id'];
    }
    
    if (!empty($filters['customer_id'])) {
        $sql .= " AND ps.customer_id = ?";
        $params[] = $filters['customer_id'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND ps.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['due_date_from'])) {
        $sql .= " AND ps.due_date >= ?";
        $params[] = $filters['due_date_from'];
    }
    
    if (!empty($filters['due_date_to'])) {
        $sql .= " AND ps.due_date <= ?";
        $params[] = $filters['due_date_to'];
    }
    
    if (!empty($filters['overdue_only'])) {
        $sql .= " AND ps.status = 'pending' AND ps.due_date < CURDATE()";
    }
    
    $sql .= " ORDER BY ps.due_date ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * تسجيل دفعة
 */
function recordPayment($scheduleId, $paymentDate, $amount = null, $notes = null, $recordedBy = null) {
    try {
        $db = db();
        
        if ($recordedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $recordedBy = $currentUser['id'] ?? null;
        }
        
        $schedule = $db->queryOne("SELECT * FROM payment_schedules WHERE id = ?", [$scheduleId]);
        
        if (!$schedule) {
            return ['success' => false, 'message' => 'الجدول الزمني غير موجود'];
        }
        
        if ($schedule['status'] === 'paid') {
            return ['success' => false, 'message' => 'تم دفع هذه الدفعة بالفعل'];
        }
        
        $paymentAmount = $amount ?? $schedule['amount'];
        
        $db->execute(
            "UPDATE payment_schedules 
             SET payment_date = ?, status = 'paid', updated_at = NOW() 
             WHERE id = ?",
            [$paymentDate, $scheduleId]
        );
        
        // تحديث المبيعات
        $db->execute(
            "UPDATE sales 
             SET paid_amount = COALESCE(paid_amount, 0) + ?, 
                 remaining_amount = COALESCE(remaining_amount, 0) - ? 
             WHERE id = ?",
            [$paymentAmount, $paymentAmount, $schedule['sale_id']]
        );
        
        logAudit($recordedBy, 'record_payment', 'payment_schedule', $scheduleId, 
                 ['old_status' => $schedule['status']], 
                 ['new_status' => 'paid', 'amount' => $paymentAmount]);
        
        return ['success' => true, 'message' => 'تم تسجيل الدفعة بنجاح'];
        
    } catch (Exception $e) {
        error_log("Payment Recording Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الدفعة'];
    }
}

/**
 * الحصول على التذكيرات المعلقة
 */
function getPendingReminders($salesRepId = null) {
    $db = db();
    
    $sql = "SELECT ps.*, c.name as customer_name, c.phone as customer_phone,
                   u.full_name as sales_rep_name
            FROM payment_schedules ps
            LEFT JOIN customers c ON ps.customer_id = c.id
            LEFT JOIN users u ON ps.sales_rep_id = u.id
            WHERE ps.status = 'pending' 
            AND ps.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND (ps.reminder_sent = 0 OR ps.reminder_sent_at < DATE_SUB(CURDATE(), INTERVAL 3 DAY))";
    
    $params = [];
    
    if ($salesRepId) {
        $sql .= " AND ps.sales_rep_id = ?";
        $params[] = $salesRepId;
    }
    
    $sql .= " ORDER BY ps.due_date ASC";
    
    return $db->query($sql, $params);
}

/**
 * إنشاء تذكير تلقائي
 */
function createAutoReminder($scheduleId, $daysBeforeDue = 3, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        $schedule = $db->queryOne("SELECT * FROM payment_schedules WHERE id = ?", [$scheduleId]);
        
        if (!$schedule || $schedule['status'] === 'paid') {
            return false;
        }
        
        $reminderDate = date('Y-m-d', strtotime($schedule['due_date'] . " - {$daysBeforeDue} days"));
        
        // التحقق من عدم وجود تذكير مسبق
        $existing = $db->queryOne(
            "SELECT id FROM payment_reminders 
             WHERE payment_schedule_id = ? AND reminder_type = 'before_due' AND days_before_due = ?",
            [$scheduleId, $daysBeforeDue]
        );
        
        if ($existing) {
            return false;
        }
        
        $db->execute(
            "INSERT INTO payment_reminders 
            (payment_schedule_id, reminder_type, reminder_date, days_before_due, 
             sent_to, created_by) 
            VALUES (?, 'before_due', ?, ?, 'sales_rep', ?)",
            [$scheduleId, $reminderDate, $daysBeforeDue, $createdBy]
        );
        
        return true;
        
    } catch (Exception $e) {
        error_log("Reminder Creation Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إرسال التذكيرات
 */
function sendPaymentReminders($salesRepId = null) {
    $db = db();
    
    $sql = "SELECT pr.*, ps.amount, ps.due_date, c.name as customer_name,
                   u.full_name as sales_rep_name, u.id as sales_rep_id
            FROM payment_reminders pr
            JOIN payment_schedules ps ON pr.payment_schedule_id = ps.id
            LEFT JOIN customers c ON ps.customer_id = c.id
            LEFT JOIN users u ON ps.sales_rep_id = u.id
            WHERE pr.sent_status = 'pending' 
              AND pr.reminder_date <= CURDATE()";
    $params = [];
    
    if (!empty($salesRepId)) {
        $sql .= " AND ps.sales_rep_id = ?";
        $params[] = $salesRepId;
    }
    
    $reminders = $db->query($sql, $params);
    
    $sentCount = 0;
    
    foreach ($reminders as $reminder) {
        $message = "تذكير: موعد تحصيل مبلغ " . formatCurrency($reminder['amount']) . 
                  " من العميل " . $reminder['customer_name'] . 
                  " في تاريخ " . formatDate($reminder['due_date']);
        
        if ($reminder['sent_to'] === 'sales_rep' || $reminder['sent_to'] === 'both') {
            createNotification(
                $reminder['sales_rep_id'],
                'تذكير بموعد تحصيل',
                $message,
                'warning',
                "dashboard/sales.php?page=sales_collections&id={$reminder['payment_schedule_id']}",
                true // إرسال Telegram
            );
        }
        
        // تحديث حالة التذكير
        $db->execute(
            "UPDATE payment_reminders 
             SET sent_status = 'sent', sent_at = NOW() 
             WHERE id = ?",
            [$reminder['id']]
        );
        
        // تحديث حالة الجدول الزمني
        $db->execute(
            "UPDATE payment_schedules 
             SET reminder_sent = 1, reminder_sent_at = NOW() 
             WHERE id = ?",
            [$reminder['payment_schedule_id']]
        );
        
        $sentCount++;
    }
    
    return $sentCount;
}

/**
 * تحديث حالة الجداول المتأخرة
 */
function updateOverdueSchedules() {
    $db = db();
    
    $updated = $db->execute(
        "UPDATE payment_schedules 
         SET status = 'overdue' 
         WHERE status = 'pending' 
         AND due_date < CURDATE()"
    );
    
    return $updated['affected_rows'] ?? 0;
}

