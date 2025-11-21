<?php
/**
 * صفحة طباعة الفاتورة المصممة للطباعة الاحترافية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// التأكد من تضمين config.php إذا لم يكن متضمناً بالفعل
if (!function_exists('formatDate') || !function_exists('formatCurrency')) {
    $configPath = __DIR__ . '/../../includes/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }
}

$isReturnDocument = isset($returnDetails) && is_array($returnDetails);
$returnMetadata = null;

if ($isReturnDocument) {
    $returnSummary = $returnDetails['summary'] ?? ($returnDetails['return'] ?? null);
    if (!$returnSummary) {
        die('المرتجع غير موجود');
    }

    $returnItems = $returnDetails['items'] ?? [];
    
    // التأكد من أن returnItems هو مصفوفة
    if (!is_array($returnItems)) {
        $returnItems = [];
    }
    
    $normalizedItems = array_map(function ($item) {
        if (!is_array($item)) {
            return null;
        }
        
        $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0;
        $unitPrice = isset($item['unit_price']) ? (float)$item['unit_price'] : 0;
        $notes = trim((string)($item['notes'] ?? ''));
        $batchNumber = $item['batch_number'] ?? null;
        $condition = $item['condition'] ?? null;
        
        return [
            'product_name' => $item['product_name'] ?? $item['description'] ?? 'منتج',
            'description'  => $notes, // الوصف يحتوي فقط على الملاحظات
            'quantity'     => $quantity,
            'unit_price'   => $unitPrice,
            'total_price'  => $item['total_price'] ?? ($quantity * $unitPrice),
            'batch_number' => $batchNumber,
            'condition'    => $condition,
            'notes'        => $notes,
        ];
    }, $returnItems);
    
    // إزالة العناصر null
    $normalizedItems = array_filter($normalizedItems, function($item) {
        return $item !== null;
    });

    $invoiceData = [
        'invoice_number'    => $returnSummary['return_number'] ?? ('RET-' . str_pad($returnSummary['id'] ?? 0, 4, '0', STR_PAD_LEFT)),
        'date'              => $returnSummary['return_date'] ?? ($returnSummary['created_at'] ?? date('Y-m-d')),
        'due_date'          => $returnSummary['return_date'] ?? ($returnSummary['created_at'] ?? date('Y-m-d')),
        'status'            => $returnSummary['status'] ?? 'pending',
        'customer_name'     => $returnSummary['customer_name'] ?? 'عميل',
        'customer_phone'    => $returnSummary['customer_phone'] ?? '',
        'customer_address'  => $returnSummary['customer_address'] ?? '',
        'sales_rep_name'    => $returnSummary['sales_rep_name'] ?? null,
        'subtotal'          => $returnSummary['refund_amount'] ?? 0,
        'discount_amount'   => 0,
        'total_amount'      => $returnSummary['refund_amount'] ?? 0,
        'paid_amount'       => $returnSummary['refund_amount'] ?? 0,
        'notes'             => trim(
            implode(
                "\n",
                array_filter([
                    !empty($returnSummary['reason']) ? 'سبب الإرجاع: ' . $returnSummary['reason'] : null,
                    !empty($returnSummary['reason_description']) ? $returnSummary['reason_description'] : null,
                    $returnSummary['notes'] ?? ''
                ])
            )
        ),
        'items'             => $normalizedItems,
        'company_address'   => $returnSummary['company_address'] ?? null,
        'company_phone'     => $returnSummary['company_phone'] ?? null,
        'company_email'     => $returnSummary['company_email'] ?? null,
        'company_tax_number'=> $returnSummary['company_tax_number'] ?? null,
    ];

    $returnMetadata = [
        'invoice_reference' => $returnSummary['invoice_number'] ?? null,
        'refund_method'     => $returnSummary['refund_method'] ?? null,
        'return_type'       => $returnSummary['return_type'] ?? null,
        'refund_amount'     => $returnSummary['refund_amount'] ?? 0,
    ];
} else {
    $invoiceData = $selectedInvoice ?? $invoice ?? null;
}

if (!$invoiceData) {
    die($isReturnDocument ? 'المرتجع غير موجود' : 'الفاتورة غير موجودة');
}

$companyName      = COMPANY_NAME;
$companySubtitle  = 'نظام إدارة المبيعات';
$companyAddress   = $invoiceData['company_address'] ?? 'الفرع الرئيسي - العنوان: ابو يوسف الرئيسي';
$companyPhone     = $invoiceData['company_phone']   ?? 'الهاتف: 0000000000';
$companyEmail     = $invoiceData['company_email']   ?? ' info@example.com : البريد الإلكتروني  ';
$companyTaxNumber = $invoiceData['company_tax_number'] ?? 'الرقم الضريبي: غير متوفر';

$issueDate = formatDate($invoiceData['date']);
$dueDateRaw = $invoiceData['due_date'] ?? null;
$dueDate = !empty($dueDateRaw) ? formatDate($dueDateRaw) : 'أجل غير مسمى';
$status    = $invoiceData['status'] ?? 'draft';

$customerName    = $invoiceData['customer_name']    ?? 'عميل نقدي';
$customerPhone   = $invoiceData['customer_phone']   ?? '';
$customerAddress = $invoiceData['customer_address'] ?? '';
$repName         = $invoiceData['sales_rep_name']   ?? null;

$subtotal        = $invoiceData['subtotal'] ?? 0;
$discount        = $invoiceData['discount_amount'] ?? 0;
$total           = $invoiceData['total_amount'] ?? 0;
$paidAmount      = $invoiceData['paid_amount'] ?? 0;
$dueAmount       = max(0, $total - $paidAmount);
$notes           = trim((string)($invoiceData['notes'] ?? ''));

$currencyLabel   = CURRENCY . ' ' . CURRENCY_SYMBOL;

// باركود فيسبوك - يمكن تعديل الرابط حسب صفحة الشركة على فيسبوك
$facebookPageUrl = 'https://www.facebook.com/yourpage'; // يرجى تعديل هذا الرابط
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($facebookPageUrl);

$statusLabelsMap = [
    'draft'     => 'مسودة',
    'approved'  => 'معتمدة',
    'paid'      => 'مدفوعة',
    'partial'   => 'مدفوع جزئياً',
    'cancelled' => 'ملغاة',
    'pending'   => 'قيد المراجعة',
    'processed' => 'تمت المعالجة',
    'completed' => 'مكتمل',
    'rejected'  => 'مرفوض'
];

$statusClassesMap = [
    'draft'     => 'status-draft',
    'approved'  => 'status-approved',
    'paid'      => 'status-paid',
    'partial'   => 'status-partial',
    'cancelled' => 'status-cancelled',
    'pending'   => 'status-draft',
    'processed' => 'status-approved',
    'completed' => 'status-paid',
    'rejected'  => 'status-cancelled'
];

$statusLabel = $statusLabelsMap[$status] ?? 'مسودة';
$statusClass = $statusClassesMap[$status] ?? 'status-draft';

$documentTitleText = $isReturnDocument ? 'فاتورة مرتجع' : 'فاتورة مبيعات';
$documentNumberLabel = $isReturnDocument ? 'رقم المرتجع' : 'رقم الفاتورة';
$summaryTitleText = $isReturnDocument ? 'ملخص المرتجع' : 'ملخص الفاتورة';

$returnRefundLabels = [
    'cash'             => 'إرجاع نقداً',
    'credit'           => 'إضافة لرصيد العميل',
    'exchange'         => 'استبدال منتجات',
    'company_request'  => 'طلب المبلغ من الشركة'
];
$returnTypeLabels = [
    'full'    => 'مرتجع كامل',
    'partial' => 'مرتجع جزئي'
];
$returnRefundLabel = $isReturnDocument ? ($returnRefundLabels[$returnMetadata['refund_method'] ?? ''] ?? 'غير محدد') : '';
$returnTypeLabel = $isReturnDocument ? ($returnTypeLabels[$returnMetadata['return_type'] ?? ''] ?? 'غير محدد') : '';
?>

<div class="invoice-wrapper" id="invoicePrint">
    <div class="invoice-card">
        <header class="invoice-header">
            <div class="brand-block">
                <div class="logo-placeholder">
                    <img src="<?php echo getRelativeUrl('assets/icons/icon-192x192.svg'); ?>" alt="Logo" class="company-logo-img" onerror="this.onerror=null; this.src='<?php echo getRelativeUrl('assets/icons/icon-192x192.png'); ?>'; this.onerror=function(){this.style.display='none'; this.nextElementSibling.style.display='flex';};">
                    <span class="logo-letter" style="display:none;"><?php echo mb_substr($companyName, 0, 1); ?></span>
                </div>
                <div>
                    <h1 class="company-name"><?php echo htmlspecialchars($companyName); ?></h1>
                    <div class="company-subtitle"><?php echo htmlspecialchars($companySubtitle); ?></div>
                </div>
            </div>
            <div class="invoice-meta">
                <div class="invoice-title"><?php echo htmlspecialchars($documentTitleText); ?></div>
                <div class="invoice-number"><?php echo htmlspecialchars($documentNumberLabel); ?><span><?php echo htmlspecialchars($invoiceData['invoice_number']); ?></span></div>
                <div class="invoice-meta-grid">
                    <div class="meta-item">
                        <span>تاريخ الإصدار</span>
                        <strong><?php echo $issueDate; ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>تاريخ الاستحقاق</span>
                        <strong><?php echo $dueDate; ?></strong>
                    </div>
                    <div class="meta-item">
                        <span>الحالة</span>
                        <strong class="<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></strong>
                    </div>
                </div>
            </div>
        </header>

        <section class="info-grid">
            <div class="info-card">
                <div class="info-title">بيانات الشركة</div>
                <div class="info-item"><?php echo htmlspecialchars($companyAddress); ?></div>
                <div class="info-item"><?php echo htmlspecialchars($companyPhone); ?></div>
                <div class="info-item"><?php echo htmlspecialchars($companyEmail); ?></div>
                <div class="info-item"><?php echo htmlspecialchars($companyTaxNumber); ?></div>
            </div>
            <div class="info-card">
                <div class="info-title">بيانات العميل</div>
                <div class="info-item name"><?php echo htmlspecialchars($customerName); ?></div>
                <?php if (!empty($customerPhone)): ?>
                    <div class="info-item">هاتف: <?php echo htmlspecialchars($customerPhone); ?></div>
                <?php endif; ?>
                <?php if (!empty($customerAddress)): ?>
                    <div class="info-item">العنوان: <?php echo htmlspecialchars($customerAddress); ?></div>
                <?php endif; ?>
                <?php if ($repName): ?>
                    <div class="info-item rep">مندوب المبيعات: <?php echo htmlspecialchars($repName); ?></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="items-table">
            <table>
                <thead>
                    <tr>
                        <th style="width: 30%;">المنتج</th>
                        <?php if ($isReturnDocument): ?>
                            <th style="width: 15%; text-align: center;">الحالة</th>
                        <?php else: ?>
                            <th style="width: 20%;">الوصف</th>
                        <?php endif; ?>
                        <?php if ($isReturnDocument && !empty($invoiceData['items']) && !empty(array_filter(array_column($invoiceData['items'], 'batch_number')))): ?>
                            <th style="width: 15%; text-align: center;">رقم التشغيلة</th>
                        <?php endif; ?>
                        <th style="width: 12%; text-align: center;">الكمية</th>
                        <th style="width: 15%; text-align: end;">سعر الوحدة</th>
                        <th style="width: 15%; text-align: end;">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // حساب عدد الأعمدة
                    $colspan = 5; // المنتج، الوصف/الحالة، الكمية، سعر الوحدة، الإجمالي
                    if ($isReturnDocument) {
                        $colspan = 5; // المنتج، الحالة، الكمية، سعر الوحدة، الإجمالي
                        if (!empty($invoiceData['items']) && !empty(array_filter(array_column($invoiceData['items'], 'batch_number')))) {
                            $colspan = 6; // إضافة عمود رقم التشغيلة
                        }
                    }
                    
                    if (empty($invoiceData['items']) || !is_array($invoiceData['items'])) {
                        echo '<tr><td colspan="' . $colspan . '" style="text-align: center; padding: 20px; color: #64748b;">لا توجد منتجات في هذا المرتجع</td></tr>';
                    } else {
                        foreach ($invoiceData['items'] as $item): 
                            $quantity   = isset($item['quantity']) ? number_format($item['quantity'], 2) : '0.00';
                            $unitPrice  = isset($item['unit_price']) ? formatCurrency($item['unit_price']) : formatCurrency(0);
                            $totalPrice = isset($item['total_price']) ? formatCurrency($item['total_price']) : formatCurrency(0);
                            $description = trim((string)($item['description'] ?? ''));
                            $batchNumber = $item['batch_number'] ?? null;
                            $condition = $item['condition'] ?? null;
                            $notes = trim((string)($item['notes'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <div class="product-name" style="font-weight: 600; margin-bottom: 4px;">
                                <?php echo htmlspecialchars($item['product_name'] ?? 'منتج'); ?>
                            </div>
                            <?php if ($notes && !$isReturnDocument): ?>
                                <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                    <?php echo nl2br(htmlspecialchars($notes)); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($isReturnDocument): ?>
                            <td style="text-align: center; vertical-align: middle;">
                                <?php if (!empty($condition)): ?>
                                    <?php
                                    $conditionLabels = [
                                        'new' => ['label' => 'جديد', 'color' => '#10b981', 'bg' => '#d1fae5'],
                                        'used' => ['label' => 'مستعمل', 'color' => '#f59e0b', 'bg' => '#fef3c7'],
                                        'damaged' => ['label' => 'تالف', 'color' => '#ef4444', 'bg' => '#fee2e2'],
                                        'defective' => ['label' => 'معيب', 'color' => '#dc2626', 'bg' => '#fecaca']
                                    ];
                                    $conditionInfo = $conditionLabels[$condition] ?? ['label' => $condition, 'color' => '#64748b', 'bg' => '#f1f5f9'];
                                    ?>
                                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; color: <?php echo $conditionInfo['color']; ?>; background: <?php echo $conditionInfo['bg']; ?>;">
                                        <?php echo htmlspecialchars($conditionInfo['label']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                        <?php else: ?>
                            <td>
                                <?php if ($description): ?>
                                    <div style="font-size: 13px; color: #475569; line-height: 1.5;">
                                        <?php echo nl2br(htmlspecialchars($description)); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="color: #9ca3af; font-size: 13px;">لا يوجد وصف</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php if ($isReturnDocument && !empty($invoiceData['items']) && !empty(array_filter(array_column($invoiceData['items'], 'batch_number')))): ?>
                            <td style="text-align: center; vertical-align: middle;">
                                <?php if (!empty($batchNumber)): ?>
                                    <span style="font-size: 12px; color: #0f4c81; font-weight: 600; background: #e0f2fe; padding: 4px 8px; border-radius: 6px; display: inline-block;">
                                        <?php echo htmlspecialchars($batchNumber); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td style="text-align: center; vertical-align: middle; font-weight: 600;">
                            <?php echo $quantity; ?>
                        </td>
                        <td style="text-align: end; vertical-align: middle;">
                            <?php echo $unitPrice; ?>
                        </td>
                        <td style="text-align: end; vertical-align: middle; font-weight: 600; color: #0f4c81;">
                            <?php echo $totalPrice; ?>
                        </td>
                    </tr>
                    <?php 
                        endforeach;
                    }
                    ?>
                </tbody>
            </table>
        </section>

        <section class="summary-grid">
            <div class="summary-card">
                <div class="summary-title"><?php echo htmlspecialchars($summaryTitleText); ?></div>
                <div class="summary-row">
                    <span>المجموع الفرعي</span>
                    <strong><?php echo formatCurrency($subtotal); ?></strong>
                </div>
                <?php if ($discount > 0): ?>
                    <div class="summary-row">
                        <span>الخصم</span>
                        <strong class="text-danger">-<?php echo formatCurrency($discount); ?></strong>
                    </div>
                <?php endif; ?>
                <div class="summary-row total">
                    <span>الإجمالي النهائي</span>
                    <strong><?php echo formatCurrency($total); ?></strong>
                </div>
                <div class="summary-row">
                    <span>المدفوع</span>
                    <strong class="text-success"><?php echo formatCurrency($paidAmount); ?></strong>
                </div>
                <div class="summary-row due">
                    <span>المتبقي</span>
                    <strong class="<?php echo $dueAmount > 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo formatCurrency($dueAmount); ?>
                    </strong>
                </div>
            </div>
            <?php if ($isReturnDocument): ?>
                <div class="summary-card">
                    <div class="summary-title">تفاصيل الإرجاع</div>
                    <?php if (!empty($returnMetadata['invoice_reference'])): ?>
                        <div class="summary-row">
                            <span>فاتورة مرتبطة</span>
                            <strong><?php echo htmlspecialchars($returnMetadata['invoice_reference']); ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="summary-row">
                        <span>طريقة الإرجاع</span>
                        <strong><?php echo htmlspecialchars($returnRefundLabel); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>نوع المرتجع</span>
                        <strong><?php echo htmlspecialchars($returnTypeLabel); ?></strong>
                    </div>
                    <div class="summary-row">
                        <span>القيمة المرتجعة</span>
                        <strong><?php echo formatCurrency($returnMetadata['refund_amount'] ?? 0); ?></strong>
                    </div>
                </div>
            <?php endif; ?>
            <div class="summary-card qr-card">
                <div class="summary-title">تابعنا على فيسبوك</div>
                <div class="qr-wrapper">
                    <img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Facebook QR Code">
                </div>
                <div class="qr-note">امسح الرمز لمتابعة صفحتنا على فيسبوك</div>
            </div>
            <?php if ($notes): ?>
                <div class="summary-card notes-card">
                    <div class="summary-title">ملاحظات</div>
                    <div class="notes-content"><?php echo nl2br(htmlspecialchars($notes)); ?></div>
                </div>
            <?php endif; ?>
        </section>

        <footer class="invoice-footer">
            <div class="thanks">نشكركم على ثقتكم بنا</div>
            <div class="terms">
                <div>يرجى التأكد من مطابقة المنتجات عند الاستلام.</div>
                <div>لأي استفسارات يرجى التواصل على: <?php echo htmlspecialchars($companyPhone); ?></div>
            </div>
        </footer>
    </div>
</div>

<style>
.invoice-wrapper {
    font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    color: #1f2937;
}

.invoice-card {
    background: #ffffff;
    border-radius: 24px;
    border: 1px solid #e2e8f0;
    padding: 32px;
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.12);
    position: relative;
    overflow: hidden;
}

.invoice-card::before {
    content: '';
    position: absolute;
    top: -40%;
    left: -25%;
    width: 60%;
    height: 120%;
    background: radial-gradient(circle at center, rgba(15, 76, 129, 0.12), transparent 70%);
    z-index: 0;
}

.invoice-card > * {
    position: relative;
    z-index: 1;
}

.invoice-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 32px;
}

.brand-block {
    display: flex;
    align-items: center;
    gap: 18px;
}

.logo-placeholder {
    width: 74px;
    height: 74px;
    border-radius: 20px;
    background: linear-gradient(135deg, #0f4c81, #0a2d4a);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 30px;
    font-weight: 700;
    box-shadow: 0 12px 24px rgba(15, 76, 129, 0.25);
    overflow: hidden;
    position: relative;
}

.company-logo-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 8px;
}

.logo-letter {
    transform: translateY(2px);
}

.company-name {
    font-size: 28px;
    font-weight: 700;
    margin: 0;
    color: #0f172a;
}

.company-subtitle {
    font-size: 14px;
    color: #475569;
    margin-top: 6px;
}

.invoice-meta {
    text-align: left;
}

.invoice-title {
    font-size: 20px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 6px;
}

.invoice-number {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 10px;
}

.invoice-number span {
    display: block;
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
}

.invoice-meta-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(160px, 1fr));
    gap: 12px;
}

.meta-item {
    background: #f8fafc;
    border: 1px solid rgba(15, 76, 129, 0.08);
    border-radius: 12px;
    padding: 12px 16px;
}

.meta-item span {
    display: block;
    font-size: 12px;
    color: #64748b;
    margin-bottom: 6px;
}

.meta-item strong {
    font-size: 15px;
    color: #0f172a;
}

.status-draft { color: #eab308; }
.status-approved { color: #10b981; }
.status-paid { color: #059669; }
.status-partial { color: #f97316; }
.status-cancelled { color: #ef4444; }

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
    margin-bottom: 24px;
}

.info-card {
    background: #f9fafb;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 18px 22px;
}

.info-title {
    font-size: 15px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 12px;
}

.info-item {
    font-size: 14px;
    color: #475569;
    margin-bottom: 6px;
    line-height: 1.6;
}

.info-item.name {
    font-weight: 600;
    color: #0f172a;
}

.info-item.rep {
    margin-top: 10px;
    color: #1d4ed8;
}

.items-table table {
    width: 100%;
    border-collapse: collapse;
    border-radius: 18px;
    overflow: hidden;
    border: 1px solid rgba(15, 76, 129, 0.12);
}

.items-table thead {
    background: linear-gradient(135deg, rgba(15, 76, 129, 0.1), rgba(15, 76, 129, 0.05));
}

.items-table th {
    padding: 14px 12px;
    font-size: 13px;
    color: #0f4c81;
    text-align: right;
    border-bottom: 1px solid rgba(15, 76, 129, 0.12);
    font-weight: 600;
}

.items-table th:first-child {
    text-align: right;
}

.items-table td {
    padding: 16px 12px;
    font-size: 14px;
    color: #1f2937;
    border-bottom: 1px solid rgba(148, 163, 184, 0.25);
    text-align: right;
    vertical-align: middle;
}

.items-table tbody tr:last-child td {
    border-bottom: none;
}

.items-table tbody tr:hover {
    background-color: rgba(15, 76, 129, 0.02);
}

.items-table .product-name {
    font-weight: 600;
    margin-bottom: 6px;
    color: #0f172a;
}

.items-table .product-unit {
    font-size: 12px;
    color: #64748b;
}

.items-table .muted {
    color: #9ca3af;
    font-size: 13px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 18px;
    margin: 28px 0;
}

.summary-card {
    background: #f8fafc;
    border-radius: 18px;
    border: 1px solid rgba(15, 76, 129, 0.1);
    padding: 20px 22px;
    min-height: 220px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.summary-card.qr-card {
    align-items: center;
    text-align: center;
}

.summary-title {
    font-size: 15px;
    font-weight: 700;
    color: #0f4c81;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    color: #1f2937;
    gap: 12px;
}

.summary-row strong {
    font-size: 15px;
}

.summary-row.total {
    border-top: 1px dashed rgba(15, 76, 129, 0.2);
    padding-top: 10px;
    margin-top: 6px;
}

.summary-row.due {
    font-weight: 600;
}

.text-success { color: #16a34a !important; }
.text-danger { color: #dc2626 !important; }

.qr-wrapper {
    background: #fff;
    padding: 12px;
    border-radius: 16px;
    border: 1px solid rgba(15, 76, 129, 0.15);
    box-shadow: inset 0 2px 12px rgba(15, 23, 42, 0.05);
}

.qr-wrapper img {
    width: 150px;
    height: 150px;
    display: block;
}

.qr-note {
    font-size: 12px;
    color: #64748b;
}

.notes-card .notes-content {
    font-size: 13px;
    color: #475569;
    line-height: 1.8;
    background: rgba(15, 76, 129, 0.05);
    padding: 12px;
    border-radius: 12px;
    border: 1px solid rgba(15, 76, 129, 0.08);
}

.invoice-footer {
    text-align: center;
    padding-top: 18px;
    border-top: 1px dashed rgba(148, 163, 184, 0.5);
}

.invoice-footer .thanks {
    font-size: 16px;
    font-weight: 600;
    color: #0f4c81;
    margin-bottom: 6px;
}

.invoice-footer .terms {
    font-size: 12px;
    color: #64748b;
    line-height: 1.6;
}

@media print {
    body {
        background: #ffffff;
        margin: 0;
    }

    .invoice-card {
        box-shadow: none;
        border: none;
        padding: 24px;
        border-radius: 0;
    }

    .invoice-card::before {
        display: none;
    }

    .btn, .no-print, .card-header, .sidebar, .navbar {
        display: none !important;
    }

    .invoice-wrapper {
        margin: 0;
    }
}

@media (max-width: 768px) {
    .invoice-card {
        padding: 24px 18px;
        border-radius: 18px;
    }

    .invoice-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .invoice-meta-grid {
        grid-template-columns: repeat(2, minmax(140px, 1fr));
    }

    .info-grid, .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>
