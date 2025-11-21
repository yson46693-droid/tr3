<?php
/**
 * إدارة سجل مشتريات العملاء
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * التأكد من وجود جدول سجل مشتريات العملاء
 */
function customerHistoryEnsureSetup(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $db = db();

    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `customer_purchase_history` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `customer_id` int(11) NOT NULL,
              `invoice_id` int(11) NOT NULL,
              `invoice_number` varchar(100) NOT NULL,
              `invoice_date` date NOT NULL,
              `invoice_total` decimal(15,2) NOT NULL DEFAULT 0.00,
              `paid_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
              `invoice_status` varchar(32) DEFAULT NULL,
              `return_total` decimal(15,2) NOT NULL DEFAULT 0.00,
              `return_count` int(11) NOT NULL DEFAULT 0,
              `exchange_total` decimal(15,2) NOT NULL DEFAULT 0.00,
              `exchange_count` int(11) NOT NULL DEFAULT 0,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `customer_invoice_unique` (`customer_id`,`invoice_id`),
              KEY `customer_invoice_date_idx` (`customer_id`,`invoice_date`),
              KEY `invoice_date_idx` (`invoice_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $tableError) {
        error_log('customerHistoryEnsureSetup: failed creating customer_purchase_history -> ' . $tableError->getMessage());
    }

    try {
        $db->execute("
            ALTER TABLE `customer_purchase_history`
              ADD COLUMN IF NOT EXISTS `invoice_status` varchar(32) DEFAULT NULL AFTER `paid_amount`,
              ADD COLUMN IF NOT EXISTS `return_total` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `invoice_status`,
              ADD COLUMN IF NOT EXISTS `return_count` int(11) NOT NULL DEFAULT 0 AFTER `return_total`,
              ADD COLUMN IF NOT EXISTS `exchange_total` decimal(15,2) NOT NULL DEFAULT 0.00 AFTER `return_count`,
              ADD COLUMN IF NOT EXISTS `exchange_count` int(11) NOT NULL DEFAULT 0 AFTER `exchange_total`,
              ADD INDEX IF NOT EXISTS `customer_invoice_date_idx` (`customer_id`,`invoice_date`)
        ");
    } catch (Throwable $alterError) {
        error_log('customerHistoryEnsureSetup: failed altering customer_purchase_history -> ' . $alterError->getMessage());
    }

    $ensured = true;
}

/**
 * حذف السجلات التي مضى عليها أكثر من ستة أشهر
 */
function customerHistoryPruneOlderThan(string $cutoffDate): void
{
    customerHistoryEnsureSetup();

    try {
        $db = db();
        $db->execute(
            "DELETE FROM customer_purchase_history WHERE invoice_date < ?",
            [$cutoffDate]
        );
    } catch (Throwable $deleteError) {
        error_log('customerHistoryPruneOlderThan: failed pruning history -> ' . $deleteError->getMessage());
    }
}

/**
 * مزامنة سجل المشتريات الخاص بالعميل لآخر ستة أشهر
 *
 * @return array{
 *   invoices: array<int, array<string, mixed>>,
 *   totals: array<string, float|int>,
 *   returns: array<int, array<string, mixed>>,
 *   exchanges: array<int, array<string, mixed>>,
 *   window_start: string
 * }
 */
function customerHistorySyncForCustomer(int $customerId): array
{
    customerHistoryEnsureSetup();

    $db = db();
    $cutoffDate = date('Y-m-d', strtotime('-6 months'));

    // حذف السجلات الأقدم من ستة أشهر
    customerHistoryPruneOlderThan($cutoffDate);

    // جلب الفواتير للعميل خلال آخر 6 أشهر (فقط الفواتير الرسمية من جدول invoices)
    $invoiceRows = $db->query(
        "SELECT id, invoice_number, date, total_amount, paid_amount, status
         FROM invoices
         WHERE customer_id = ? AND date >= ?
         ORDER BY date DESC",
        [$customerId, $cutoffDate]
    );
    
    // تسجيل عدد الفواتير للتشخيص
    error_log("customerHistorySyncForCustomer: Found " . count($invoiceRows) . " invoices for customer_id=$customerId since $cutoffDate");

    // استخدام الفواتير فقط (بدون المبيعات المباشرة)
    $allInvoiceRows = $invoiceRows;

    // إنشاء معرفات الفواتير
    $invoiceIds = [];
    
    foreach ($allInvoiceRows as $row) {
        $realId = (int)($row['id'] ?? 0);
        if ($realId > 0) {
            $invoiceIds[] = $realId;
        }
    }

    // تجميع بيانات المرتجعات حسب الفاتورة (فقط للفواتير الحقيقية، ليس للمبيعات)
    $returnsByInvoice = [];
    $realInvoiceIds = array_filter($invoiceIds, function($id) { return $id > 0; });
    if (!empty($realInvoiceIds)) {
        $placeholders = implode(',', array_fill(0, count($realInvoiceIds), '?'));
        $returnRows = $db->query(
            "SELECT invoice_id, COUNT(*) as return_count, COALESCE(SUM(refund_amount), 0) as return_total
             FROM returns
             WHERE customer_id = ?
               AND invoice_id IN ($placeholders)
               AND return_date >= ?
             GROUP BY invoice_id",
            array_merge([$customerId], $realInvoiceIds, [$cutoffDate])
        );

        foreach ($returnRows as $returnRow) {
            $invoiceId = (int)($returnRow['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            $returnsByInvoice[$invoiceId] = [
                'count' => (int)($returnRow['return_count'] ?? 0),
                'total' => (float)($returnRow['return_total'] ?? 0.0),
            ];
        }
    }

    // تجميع بيانات الاستبدالات عبر المرتجعات المرتبطة بالفواتير (فقط للفواتير الحقيقية)
    $exchangesByInvoice = [];
    if (!empty($realInvoiceIds)) {
        $placeholders = implode(',', array_fill(0, count($realInvoiceIds), '?'));
        $exchangeRows = $db->query(
            "SELECT
                e.id,
                e.exchange_date,
                e.exchange_type,
                e.difference_amount,
                e.new_total,
                e.original_total,
                r.invoice_id
             FROM exchanges e
             LEFT JOIN returns r ON e.return_id = r.id
             WHERE e.customer_id = ?
               AND e.exchange_date >= ?
               AND r.invoice_id IS NOT NULL
               AND r.invoice_id IN ($placeholders)",
            array_merge([$customerId, $cutoffDate], $realInvoiceIds)
        );

        foreach ($exchangeRows as $exchangeRow) {
            $invoiceId = (int)($exchangeRow['invoice_id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }
            if (!isset($exchangesByInvoice[$invoiceId])) {
                $exchangesByInvoice[$invoiceId] = [
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
            $exchangesByInvoice[$invoiceId]['count']++;
            $exchangesByInvoice[$invoiceId]['total'] += (float)($exchangeRow['difference_amount'] ?? 0.0);
        }
    }

    // تحديث أو إنشاء السجلات في جدول التاريخ (فقط للفواتير الرسمية)
    foreach ($allInvoiceRows as $invoiceRow) {
        $invoiceId = (int)($invoiceRow['id'] ?? 0);
        
        if ($invoiceId <= 0) {
            continue;
        }

        $invoiceTotal = (float)($invoiceRow['total_amount'] ?? 0.0);
        $paidAmount = (float)($invoiceRow['paid_amount'] ?? 0.0);
        $status = (string)($invoiceRow['status'] ?? '');

        // تجميع بيانات المرتجعات والاستبدالات للفواتير
        $returnsData = $returnsByInvoice[$invoiceId] ?? ['count' => 0, 'total' => 0.0];
        $exchangesData = $exchangesByInvoice[$invoiceId] ?? ['count' => 0, 'total' => 0.0];

        try {
            // حفظ سجل الفاتورة في جدول التاريخ
            $db->execute(
                "INSERT INTO customer_purchase_history
                    (customer_id, invoice_id, invoice_number, invoice_date, invoice_total, paid_amount, invoice_status,
                     return_total, return_count, exchange_total, exchange_count, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    invoice_number = VALUES(invoice_number),
                    invoice_date = VALUES(invoice_date),
                    invoice_total = VALUES(invoice_total),
                    paid_amount = VALUES(paid_amount),
                    invoice_status = VALUES(invoice_status),
                    return_total = VALUES(return_total),
                    return_count = VALUES(return_count),
                    exchange_total = VALUES(exchange_total),
                    exchange_count = VALUES(exchange_count),
                    updated_at = NOW()",
                [
                    $customerId,
                    $invoiceId,
                    $invoiceRow['invoice_number'] ?? '',
                    $invoiceRow['date'] ?? date('Y-m-d'),
                    $invoiceTotal,
                    $paidAmount,
                    $status,
                    $returnsData['total'],
                    $returnsData['count'],
                    $exchangesData['total'],
                    $exchangesData['count'],
                ]
            );
        } catch (Throwable $upsertError) {
            error_log('customerHistorySyncForCustomer: failed upserting invoice history -> ' . $upsertError->getMessage());
        }
    }

    // إزالة السجلات الزائدة للعميل إذا لم يكن لديه فواتير ضمن النافذة الزمنية
    // أيضاً إزالة أي سجلات SALE (المبيعات المباشرة) لأننا لا نعرضها بعد الآن
    try {
        if (!empty($invoiceIds)) {
            $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
            $params = array_merge([$customerId], $invoiceIds);
            $db->execute(
                "DELETE FROM customer_purchase_history
                 WHERE customer_id = ? 
                   AND (invoice_id NOT IN ($placeholders) OR invoice_number LIKE 'SALE-%' OR invoice_id < 0)",
                $params
            );
        } else {
            // إذا لم تكن هناك فواتير، احذف جميع السجلات بما فيها SALE
            $db->execute(
                "DELETE FROM customer_purchase_history 
                 WHERE customer_id = ?",
                [$customerId]
            );
        }
    } catch (Throwable $cleanupError) {
        error_log('customerHistorySyncForCustomer: failed cleaning stale entries -> ' . $cleanupError->getMessage());
    }

    // إرجاع البيانات بعد المزامنة
    $historyRows = $db->query(
        "SELECT *
         FROM customer_purchase_history
         WHERE customer_id = ?
         ORDER BY invoice_date DESC",
        [$customerId]
    );
    
    // تسجيل عدد السجلات للتشخيص
    error_log("customerHistorySyncForCustomer: Found " . count($historyRows) . " history records for customer_id=$customerId");

    $invoicesPayload = [];
    $summaryTotals = [
        'invoice_count'   => 0,
        'total_invoiced'  => 0.0,
        'total_paid'      => 0.0,
        'total_returns'   => 0.0,
        'total_exchanges' => 0.0,
        'net_total'       => 0.0,
    ];

    foreach ($historyRows as $row) {
        $invoiceTotal = (float)($row['invoice_total'] ?? 0.0);
        $paidAmount = (float)($row['paid_amount'] ?? 0.0);
        $returnTotal = (float)($row['return_total'] ?? 0.0);
        $exchangeTotal = (float)($row['exchange_total'] ?? 0.0);

        $summaryTotals['invoice_count']++;
        $summaryTotals['total_invoiced'] += $invoiceTotal;
        $summaryTotals['total_paid'] += $paidAmount;
        $summaryTotals['total_returns'] += $returnTotal;
        $summaryTotals['total_exchanges'] += $exchangeTotal;

        $net = $invoiceTotal - $returnTotal + $exchangeTotal;
        $summaryTotals['net_total'] += $net;

        $invoicesPayload[] = [
            'invoice_id'      => (int)$row['invoice_id'],
            'invoice_number'  => $row['invoice_number'],
            'invoice_date'    => $row['invoice_date'],
            'invoice_total'   => $invoiceTotal,
            'paid_amount'     => $paidAmount,
            'invoice_status'  => $row['invoice_status'],
            'return_total'    => $returnTotal,
            'return_count'    => (int)($row['return_count'] ?? 0),
            'exchange_total'  => $exchangeTotal,
            'exchange_count'  => (int)($row['exchange_count'] ?? 0),
            'net_total'       => $net,
        ];
    }

    // الحصول على تفاصيل المرتجعات والاستبدالات للعرض المفصل
    $recentReturns = $db->query(
        "SELECT r.id, r.invoice_id, r.return_number, r.return_date, r.refund_amount, r.return_type, r.status
         FROM returns r
         WHERE r.customer_id = ?
           AND r.return_date >= ?
         ORDER BY r.return_date DESC",
        [$customerId, $cutoffDate]
    );

    $recentExchanges = $db->query(
        "SELECT e.id, e.exchange_number, e.exchange_date, e.exchange_type, e.difference_amount, e.status,
                r.invoice_id
         FROM exchanges e
         LEFT JOIN returns r ON e.return_id = r.id
         WHERE e.customer_id = ?
           AND e.exchange_date >= ?
         ORDER BY e.exchange_date DESC",
        [$customerId, $cutoffDate]
    );

    return [
        'window_start' => $cutoffDate,
        'invoices'     => $invoicesPayload,
        'totals'       => $summaryTotals,
        'returns'      => $recentReturns,
        'exchanges'    => $recentExchanges,
    ];
}

/**
 * الحصول على تاريخ المشتريات
 *
 * @return array<string,mixed>
 */
function customerHistoryGetHistory(int $customerId): array
{
    $db = db();
    $customer = $db->queryOne(
        "SELECT id, name, phone, address FROM customers WHERE id = ? LIMIT 1",
        [$customerId]
    );

    if (!$customer) {
        return [
            'success' => false,
            'message' => 'العميل غير موجود',
        ];
    }

    $history = customerHistorySyncForCustomer($customerId);

    return [
        'success'      => true,
        'customer'     => $customer,
        'history'      => $history,
    ];
}

