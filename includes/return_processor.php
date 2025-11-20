<?php
/**
 * Unified Return & Exchange Processor
 * Implements the unified algorithm for handling returns and exchanges
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/inventory_movements.php';
require_once __DIR__ . '/vehicle_inventory.php';
require_once __DIR__ . '/approval_system.php';

/**
 * Determine customer status based on payment and balance
 * 
 * @param float $amountPaid
 * @param float $invoiceTotal
 * @param float $customerBalance
 * @return array Status information
 */
function determineCustomerStatus(float $amountPaid, float $invoiceTotal, float $customerBalance): array
{
    $status = [];
    
    if (abs($amountPaid - $invoiceTotal) < 0.01) {
        $status['payment'] = 'FullyPaid';
    } elseif ($amountPaid > 0.0001 && $amountPaid < $invoiceTotal) {
        $status['payment'] = 'PartiallyPaid';
    } elseif ($amountPaid < 0.0001) {
        $status['payment'] = 'NotPaid';
    } else {
        $status['payment'] = 'Unknown';
    }
    
    $status['hasCredit'] = $customerBalance > 0.0001;
    $status['creditAmount'] = $customerBalance > 0 ? $customerBalance : 0;
    
    return $status;
}

/**
 * Process return using unified formula
 * 
 * Formula: newCustomerBalance = customerBalance + amountPaid - invoiceTotal + returnAmount
 * 
 * @param int $customerId
 * @param float $invoiceTotal
 * @param float $amountPaid
 * @param float $customerBalance
 * @param float $returnAmount
 * @param string $refundMethod 'cash' or 'credit'
 * @param int $salesRepId
 * @param string $returnNumber
 * @param int $userId
 * @return array Result with new balance and action taken
 */
function processReturnFinancial(
    int $customerId,
    float $invoiceTotal,
    float $amountPaid,
    float $customerBalance,
    float $returnAmount,
    string $refundMethod,
    int $salesRepId,
    string $returnNumber,
    int $userId
): array {
    $db = db();
    
    // Calculate unpaid amount
    $unpaidAmount = round($invoiceTotal - $amountPaid, 2);
    
    // Determine action based on algorithm
    if ($returnAmount <= $unpaidAmount + 0.0001) {
        // Action: ReduceCustomerDebt
        // The return reduces the customer's debt
        // Formula: newCustomerBalance = customerBalance + amountPaid - invoiceTotal + returnAmount
        $newCustomerBalance = round($customerBalance + $amountPaid - $invoiceTotal + $returnAmount, 2);
        
        $action = 'ReduceCustomerDebt';
        $financialNote = sprintf('تم خصم %s من رصيد العميل المدين.', formatCurrency($returnAmount));
        
        // Update customer balance
        $db->execute(
            "UPDATE customers SET balance = ? WHERE id = ?",
            [$newCustomerBalance, $customerId]
        );
        
        return [
            'success' => true,
            'action' => $action,
            'newBalance' => $newCustomerBalance,
            'financialNote' => $financialNote,
            'debtorUsed' => $returnAmount,
            'remainingRefund' => 0
        ];
    } else {
        // Action: RefundOrCredit
        // Return amount exceeds unpaid amount, so we need to refund/credit the excess
        $excessAmount = round($returnAmount - $unpaidAmount, 2);
        
        // First, reduce the unpaid debt to zero
        // Balance after reducing unpaid debt: customerBalance + amountPaid - invoiceTotal
        $balanceAfterDebtReduction = round($customerBalance + $amountPaid - $invoiceTotal, 2);
        
        // Handle excess amount based on refund method
        if ($refundMethod === 'cash') {
            // Check sales rep cash balance
            $cashBalance = calculateSalesRepCashBalance($salesRepId);
            if ($cashBalance + 0.0001 < $excessAmount) {
                return [
                    'success' => false,
                    'message' => 'رصيد خزنة المندوب لا يغطي قيمة المرتجع المتبقية. الرصيد الحالي: ' . 
                                 number_format($cashBalance, 2) . ' ج.م والمبلغ المطلوب: ' . 
                                 number_format($excessAmount, 2) . ' ج.م'
                ];
            }
            
            // Deduct excess from sales rep cash
            insertNegativeCollection($customerId, $salesRepId, $excessAmount, $returnNumber, $userId);
            
            // Final balance = balance after debt reduction (excess handled via cash, no balance change)
            $finalBalance = $balanceAfterDebtReduction;
            
            if ($unpaidAmount > 0.0001) {
                $financialNote = sprintf('تم خصم %s من رصيد العميل المدين وخصم %s نقداً من خزنة المندوب.', 
                    formatCurrency($unpaidAmount), formatCurrency($excessAmount));
            } else {
                $financialNote = sprintf('تم خصم %s نقداً من خزنة المندوب.', formatCurrency($excessAmount));
            }
        } elseif ($refundMethod === 'credit') {
            // Add excess to customer credit (reduce debt further)
            // Formula: newCustomerBalance = customerBalance + amountPaid - invoiceTotal + returnAmount
            $finalBalance = round($customerBalance + $amountPaid - $invoiceTotal + $returnAmount, 2);
            
            if ($unpaidAmount > 0.0001) {
                $financialNote = sprintf('تم خصم %s من رصيد العميل المدين وإضافة %s لرصيد العميل الدائن.', 
                    formatCurrency($unpaidAmount), formatCurrency($excessAmount));
            } else {
                $financialNote = sprintf('تم إضافة %s لرصيد العميل الدائن.', formatCurrency($excessAmount));
            }
        } else {
            // company_request or other methods
            $finalBalance = $balanceAfterDebtReduction;
            $financialNote = sprintf('تم خصم %s من رصيد العميل المدين. المبلغ المتبقي %s يحتاج موافقة.', 
                formatCurrency($unpaidAmount), formatCurrency($excessAmount));
        }
        
        // Update customer balance
        $db->execute(
            "UPDATE customers SET balance = ? WHERE id = ?",
            [$finalBalance, $customerId]
        );
        
        return [
            'success' => true,
            'action' => 'RefundOrCredit',
            'newBalance' => $finalBalance,
            'financialNote' => $financialNote,
            'debtorUsed' => $unpaidAmount,
            'remainingRefund' => $excessAmount
        ];
    }
}

/**
 * Process exchange: return + new invoice
 * 
 * @param int $customerId
 * @param float $invoiceTotal
 * @param float $amountPaid
 * @param float $customerBalance
 * @param float $returnAmount
 * @param array $newItems Array of new items for exchange
 * @param int $salesRepId
 * @param string $returnNumber
 * @param int $userId
 * @param string $exchangeDate
 * @return array Result with new invoice and financial details
 */
function processExchange(
    int $customerId,
    float $invoiceTotal,
    float $amountPaid,
    float $customerBalance,
    float $returnAmount,
    array $newItems,
    int $salesRepId,
    string $returnNumber,
    int $userId,
    string $exchangeDate
): array {
    require_once __DIR__ . '/invoices.php';
    
    // Step 1: Process return
    $returnResult = processReturnFinancial(
        $customerId,
        $invoiceTotal,
        $amountPaid,
        $customerBalance,
        $returnAmount,
        'credit', // Exchange always uses credit method for return part
        $salesRepId,
        $returnNumber,
        $userId
    );
    
    if (!$returnResult['success']) {
        return $returnResult;
    }
    
    // Step 2: Create new invoice
    $newInvoiceTotal = 0;
    foreach ($newItems as $item) {
        $newInvoiceTotal += ($item['quantity'] * $item['unit_price']);
    }
    $newInvoiceTotal = round($newInvoiceTotal, 2);
    
    // Step 3: Calculate difference
    $difference = round($newInvoiceTotal - $returnAmount, 2);
    
    // Step 4: Create new invoice
    $newInvoiceResult = createInvoice(
        $customerId,
        $salesRepId,
        $exchangeDate,
        $newItems,
        0, // taxRate
        0, // discountAmount
        "استبدال - مرتجع رقم {$returnNumber}",
        $userId
    );
    
    if (!$newInvoiceResult['success']) {
        return [
            'success' => false,
            'message' => 'فشل إنشاء الفاتورة الجديدة: ' . ($newInvoiceResult['message'] ?? 'خطأ غير معروف')
        ];
    }
    
    // Step 5: Handle difference
    $db = db();
    $currentBalance = (float)($db->queryOne("SELECT balance FROM customers WHERE id = ?", [$customerId])['balance'] ?? 0);
    
    if ($difference > 0.0001) {
        // Customer pays difference
        $newBalance = round($currentBalance + $difference, 2);
        $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
        
        return [
            'success' => true,
            'newInvoiceId' => $newInvoiceResult['invoice_id'],
            'newInvoiceNumber' => $newInvoiceResult['invoice_number'],
            'difference' => $difference,
            'action' => 'customerPays',
            'newBalance' => $newBalance,
            'financialNote' => sprintf('تم إنشاء فاتورة جديدة رقم %s. الفرق %s يضاف لرصيد العميل المدين.', 
                $newInvoiceResult['invoice_number'], formatCurrency($difference))
        ];
    } elseif ($difference < -0.0001) {
        // Refund or credit the difference
        $refundAmount = abs($difference);
        $newBalance = round($currentBalance - $refundAmount, 2);
        $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
        
        return [
            'success' => true,
            'newInvoiceId' => $newInvoiceResult['invoice_id'],
            'newInvoiceNumber' => $newInvoiceResult['invoice_number'],
            'difference' => $difference,
            'action' => 'refundOrCredit',
            'newBalance' => $newBalance,
            'financialNote' => sprintf('تم إنشاء فاتورة جديدة رقم %s. الفرق %s يضاف لرصيد العميل الدائن.', 
                $newInvoiceResult['invoice_number'], formatCurrency($refundAmount))
        ];
    } else {
        // No financial action needed
        return [
            'success' => true,
            'newInvoiceId' => $newInvoiceResult['invoice_id'],
            'newInvoiceNumber' => $newInvoiceResult['invoice_number'],
            'difference' => 0,
            'action' => 'noFinancialAction',
            'newBalance' => $currentBalance,
            'financialNote' => sprintf('تم إنشاء فاتورة جديدة رقم %s. لا يوجد فرق مالي.', 
                $newInvoiceResult['invoice_number'])
        ];
    }
}

