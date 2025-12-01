<?php
/**
 * صفحة طباعة التقرير الشامل للإنتاج
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/production_helper.php';
require_once __DIR__ . '/includes/path_helper.php';
require_once __DIR__ . '/includes/consumption_reports.php';

requireRole(['production', 'manager', 'accountant']);

$db = db();
$currentUser = getCurrentUser();

// معالجة المعاملات
$reportMonthParam = isset($_GET['report_month']) ? trim((string)$_GET['report_month']) : '';
$reportDayParam = isset($_GET['report_day']) ? trim((string)$_GET['report_day']) : '';
$reportFilterType = isset($_GET['report_type']) ? strtolower(trim((string)$_GET['report_type'])) : 'all';
if (!in_array($reportFilterType, ['all', 'packaging', 'raw'], true)) {
    $reportFilterType = 'all';
}
$supplyCategoryParam = isset($_GET['supply_category']) ? trim((string)$_GET['supply_category']) : '';
$reportFilterQuery = isset($_GET['report_query']) ? trim((string)$_GET['report_query']) : '';
$period = isset($_GET['period']) ? trim((string)$_GET['period']) : 'month'; // 'day' or 'month'

$productionReportsTodayDate = date('Y-m-d');

// معالجة فلترة الشهر
$selectedMonth = date('Y-m'); // الشهر الحالي كافتراضي
if ($reportMonthParam !== '') {
    // التحقق من صحة تنسيق الشهر (YYYY-MM)
    $monthDate = DateTime::createFromFormat('Y-m', $reportMonthParam);
    if ($monthDate && $monthDate->format('Y-m') === $reportMonthParam) {
        $selectedMonth = $reportMonthParam;
    }
}

// حساب بداية ونهاية الشهر المحدد
$selectedMonthDate = DateTime::createFromFormat('Y-m', $selectedMonth);
$productionReportsMonthStart = $selectedMonthDate->format('Y-m-01');
$lastDayOfMonth = $selectedMonthDate->format('t');
$productionReportsMonthEnd = $selectedMonthDate->format('Y-m-' . $lastDayOfMonth);

// إذا كان الشهر المحدد هو الشهر الحالي، لا نعرض أيام بعد اليوم
if ($selectedMonth === date('Y-m') && strtotime($productionReportsMonthEnd) > strtotime($productionReportsTodayDate)) {
    $productionReportsMonthEnd = $productionReportsTodayDate;
}

$selectedReportDay = $productionReportsTodayDate;
if ($reportDayParam !== '') {
    $reportDayDate = DateTime::createFromFormat('Y-m-d', $reportDayParam);
    if ($reportDayDate && $reportDayDate->format('Y-m-d') === $reportDayParam) {
        $selectedReportDay = $reportDayParam;
    }
}
if (strtotime($selectedReportDay) < strtotime($productionReportsMonthStart)) {
    $selectedReportDay = $productionReportsMonthStart;
}
if (strtotime($selectedReportDay) > strtotime($productionReportsMonthEnd)) {
    $selectedReportDay = $productionReportsMonthEnd;
}

$supplyCategoryLabels = [
    'honey' => 'العسل',
    'olive_oil' => 'زيت الزيتون',
    'beeswax' => 'شمع العسل',
    'derivatives' => 'المشتقات',
    'nuts' => 'المكسرات',
    'sesame' => 'السمسم',
    'tahini' => 'الطحينة'
];

// تحديد الفترة
$startDate = $period === 'day' ? $selectedReportDay : $productionReportsMonthStart;
$endDate = $period === 'day' ? $selectedReportDay : $productionReportsMonthEnd;

// تعريف الدوال المساعدة (إذا لم تكن موجودة)
if (!function_exists('productionPageNormalizeText')) {
    function productionPageNormalizeText($value): string {
        $value = (string) $value;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('productionPageFilterItems')) {
    function productionPageFilterItems(array $items, string $activeType, string $query, string $category): array {
        if ($activeType !== 'all' && $activeType !== $category) {
            return [];
        }
        $normalizedQuery = productionPageNormalizeText($query);
        if ($normalizedQuery === '') {
            return array_values($items);
        }
        $filtered = [];
        foreach ($items as $item) {
            $name = productionPageNormalizeText($item['name'] ?? $item['material_name'] ?? '');
            $subCategory = productionPageNormalizeText($item['sub_category'] ?? '');
            if (strpos($name, $normalizedQuery) !== false || strpos($subCategory, $normalizedQuery) !== false) {
                $filtered[] = $item;
            }
        }
        return array_values($filtered);
    }
}

if (!function_exists('productionPageAggregateTotals')) {
    function productionPageAggregateTotals(array $items): array {
        $totals = [
            'total_out' => 0.0,
            'total_in' => 0.0,
            'net' => 0.0,
            'movements' => 0,
        ];
        foreach ($items as $item) {
            $totals['total_out'] += (float) ($item['total_out'] ?? 0);
            $totals['total_in'] += (float) ($item['total_in'] ?? 0);
            $totals['movements'] += (int) ($item['movements'] ?? 0);
        }
        $totals['net'] = $totals['total_out'] - $totals['total_in'];
        $totals['total_out'] = round($totals['total_out'], 3);
        $totals['total_in'] = round($totals['total_in'], 3);
        $totals['net'] = round($totals['net'], 3);
        return $totals;
    }
}

if (!function_exists('productionPageFormatDatePart')) {
    function productionPageFormatDatePart(?string $timestamp): string {
        if (empty($timestamp)) {
            return '—';
        }
        $ts = strtotime((string)$timestamp);
        if ($ts === false) {
            return '—';
        }
        if (function_exists('formatDate')) {
            $formatted = formatDate($timestamp);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('productionPageFormatTimePart')) {
    function productionPageFormatTimePart(?string $timestamp): string {
        if (empty($timestamp)) {
            return '';
        }
        if (function_exists('formatTime')) {
            $formatted = formatTime($timestamp);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }
        $ts = strtotime((string)$timestamp);
        if ($ts === false) {
            return '';
        }
        return date('H:i', $ts);
    }
}

if (!function_exists('productionPageBuildDamagePayload')) {
    function productionPageBuildDamagePayload(array $packagingDamage, array $rawDamage): array {
        $summaryRows = [];
        $rawCategoryLabels = [];
        
        foreach (($rawDamage['categories'] ?? []) as $categoryKey => $categoryData) {
            $rawCategoryLabels[$categoryKey] = $categoryData['label'] ?? $categoryKey;
        }
        
        $packagingTotal = isset($packagingDamage['total']) ? (float)$packagingDamage['total'] : 0.0;
        $packagingEntries = isset($packagingDamage['entries']) ? (int)$packagingDamage['entries'] : (is_countable($packagingDamage['logs'] ?? null) ? count($packagingDamage['logs']) : 0);
        $packagingLastRecordedAt = $packagingDamage['last_recorded_at'] ?? null;
        $packagingLastRecordedBy = $packagingDamage['last_recorded_by'] ?? null;
        
        if ($packagingEntries > 0 || $packagingTotal > 0.0) {
            $summaryRows[] = [
                'label' => 'أدوات التعبئة',
                'category_key' => 'packaging',
                'total' => round($packagingTotal, 3),
                'entries' => $packagingEntries,
                'last_recorded_at' => $packagingLastRecordedAt,
                'last_recorded_by' => $packagingLastRecordedBy,
            ];
        }
        
        foreach (($rawDamage['categories'] ?? []) as $categoryKey => $categoryData) {
            $categoryTotal = isset($categoryData['total']) ? (float)$categoryData['total'] : 0.0;
            $categoryEntries = isset($categoryData['entries']) ? (int)$categoryData['entries'] : (is_countable($categoryData['items'] ?? null) ? count($categoryData['items']) : 0);
            
            if ($categoryEntries <= 0 && $categoryTotal <= 0.0) {
                continue;
            }
            
            $summaryRows[] = [
                'label' => 'قسم ' . ($categoryData['label'] ?? $categoryKey),
                'category_key' => $categoryKey,
                'total' => round($categoryTotal, 3),
                'entries' => $categoryEntries,
                'last_recorded_at' => $categoryData['last_recorded_at'] ?? null,
                'last_recorded_by' => $categoryData['last_recorded_by'] ?? null,
            ];
        }
        
        $entries = [];
        
        foreach (($packagingDamage['logs'] ?? []) as $log) {
            $createdAt = $log['created_at'] ?? null;
            $materialName = trim((string)($log['material_label'] ?? $log['material_name'] ?? ''));
            if ($materialName === '') {
                $materialId = isset($log['material_id']) ? (int)$log['material_id'] : 0;
                $materialName = 'أداة #' . ($materialId > 0 ? $materialId : '?');
            }
            
            $entries[] = [
                'recorded_at' => $createdAt,
                'category_label' => 'أدوات التعبئة',
                'material_label' => $materialName,
                'quantity' => (float)($log['damaged_quantity'] ?? 0),
                'unit' => trim((string)($log['unit'] ?? 'وحدة')),
                'reason' => trim((string)($log['reason'] ?? '')),
                'recorded_by_name' => trim((string)($log['recorded_by_name'] ?? '')),
            ];
        }
        
        foreach (($rawDamage['logs'] ?? []) as $log) {
            $createdAt = $log['created_at'] ?? null;
            $categoryKey = $log['material_category'] ?? '';
            $categoryLabel = $rawCategoryLabels[$categoryKey] ?? 'مواد خام';
            $itemName = trim((string)($log['item_label'] ?? 'مادة خام'));
            $variety = trim((string)($log['variety'] ?? ''));
            if ($variety !== '') {
                $itemName .= ' - ' . $variety;
            }
            
            $entries[] = [
                'recorded_at' => $createdAt,
                'category_label' => $categoryLabel,
                'material_label' => $itemName,
                'quantity' => (float)($log['damaged_quantity'] ?? 0),
                'unit' => trim((string)($log['unit'] ?? 'كجم')),
                'reason' => trim((string)($log['reason'] ?? '')),
                'recorded_by_name' => trim((string)($log['recorded_by_name'] ?? '')),
            ];
        }
        
        return [
            'summary' => $summaryRows,
            'logs' => $entries
        ];
    }
}

// جلب البيانات
$consumptionData = getConsumptionSummary($startDate, $endDate);
$supplyLogs = getProductionSupplyLogs($startDate, $endDate, $supplyCategoryParam !== '' ? $supplyCategoryParam : null);

// تصفية البيانات
$showPackagingReports = $reportFilterType === 'all' || $reportFilterType === 'packaging';
$showRawReports = $reportFilterType === 'all' || $reportFilterType === 'raw';

$filteredPackagingItems = [];
$filteredRawItems = [];

if ($showPackagingReports) {
    $filteredPackagingItems = productionPageFilterItems(
        $consumptionData['packaging']['items'] ?? [], 
        $reportFilterType, 
        $reportFilterQuery, 
        'packaging'
    );
}

if ($showRawReports) {
    $filteredRawItems = productionPageFilterItems(
        $consumptionData['raw']['items'] ?? [], 
        $reportFilterType, 
        $reportFilterQuery, 
        'raw'
    );
}

// حساب الإجماليات
$packagingTotals = productionPageAggregateTotals($filteredPackagingItems);
$rawTotals = productionPageAggregateTotals($filteredRawItems);
$totalNet = round($packagingTotals['net'] + $rawTotals['net'], 3);
$totalMovements = $packagingTotals['movements'] + $rawTotals['movements'];

// بيانات التلفيات
$damagePayload = productionPageBuildDamagePayload(
    $consumptionData['packaging_damage'] ?? [],
    $consumptionData['raw_damage'] ?? []
);
$damageSummaryRows = $damagePayload['summary'];
$damageLogs = $damagePayload['logs'];
$damageTotal = 0.0;
$damageEntries = 0;
foreach ($damageSummaryRows as $row) {
    $damageTotal += (float)($row['total'] ?? 0);
    $damageEntries += (int)($row['entries'] ?? 0);
}

// إجمالي التوريدات
$supplyTotalQuantity = 0.0;
$supplySuppliersSet = [];
foreach ($supplyLogs as $logItem) {
    $supplyTotalQuantity += isset($logItem['quantity']) ? (float)$logItem['quantity'] : 0.0;
    if (!empty($logItem['supplier_id'])) {
        $supplySuppliersSet['id_' . $logItem['supplier_id']] = true;
    } elseif (!empty($logItem['supplier_name'])) {
        $supplySuppliersSet['name_' . mb_strtolower(trim((string)$logItem['supplier_name']), 'UTF-8')] = true;
    }
}
$supplySuppliersCount = count($supplySuppliersSet);

// تنسيق التواريخ
$startDateLabel = function_exists('formatDate') ? formatDate($startDate) : $startDate;
$endDateLabel = function_exists('formatDate') ? formatDate($endDate) : $endDate;
$periodLabel = $period === 'day' ? 'يوم ' . $startDateLabel : 'من ' . $startDateLabel . ' إلى ' . $endDateLabel;

$companyName = COMPANY_NAME;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير شامل للإنتاج - <?php echo htmlspecialchars($periodLabel); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                margin: 0;
                padding: 20px;
            }
            .report-container {
                box-shadow: none;
                border: none;
            }
            .page-break {
                page-break-after: always;
            }
        }
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .report-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        .report-header {
            text-align: center;
            border-bottom: 3px solid #0f4c81;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #0f4c81;
            margin-bottom: 10px;
        }
        .report-title {
            font-size: 22px;
            color: #333;
            margin-top: 10px;
        }
        .report-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .meta-item {
            flex: 1;
        }
        .meta-label {
            font-weight: bold;
            color: #666;
            font-size: 14px;
        }
        .meta-value {
            color: #333;
            font-size: 16px;
            margin-top: 5px;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            color: #0f4c81;
            margin-top: 30px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .summary-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-right: 4px solid #0f4c81;
        }
        .summary-label {
            display: block;
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .summary-value {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #0f4c81;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        table th {
            background: #0f4c81;
            color: white;
            padding: 12px;
            text-align: right;
            font-weight: bold;
        }
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        table tr:hover {
            background: #f8f9fa;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .badge-primary {
            background: #0f4c81;
            color: white;
        }
        .badge-success {
            background: #28a745;
            color: white;
        }
        .badge-danger {
            background: #dc3545;
            color: white;
        }
        .badge-warning {
            background: #ffc107;
            color: #333;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
            text-align: center;
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 20px;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer me-2"></i>طباعة التقرير
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="bi bi-x-circle me-2"></i>إغلاق
        </button>
    </div>

    <div class="report-container">
        <div class="report-header">
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="report-title">تقرير شامل لحركات الإنتاج</div>
            <div class="report-meta">
                <div class="meta-item">
                    <div class="meta-label">الفترة:</div>
                    <div class="meta-value"><?php echo htmlspecialchars($periodLabel); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">تاريخ الطباعة:</div>
                    <div class="meta-value"><?php echo date('Y-m-d H:i'); ?></div>
                </div>
                <div class="meta-item">
                    <div class="meta-label">المستخدم:</div>
                    <div class="meta-value"><?php echo htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'] ?? 'غير محدد'); ?></div>
                </div>
            </div>
        </div>

        <!-- ملخص عام -->
        <div class="section-title">ملخص عام</div>
        <div class="summary-grid">
            <?php if ($showPackagingReports): ?>
            <div class="summary-card">
                <span class="summary-label">إجمالي استهلاك التعبئة</span>
                <span class="summary-value"><?php echo number_format((float)($packagingTotals['total_out'] ?? 0), 3); ?> وحدة</span>
            </div>
            <?php endif; ?>
            <?php if ($showRawReports): ?>
            <div class="summary-card">
                <span class="summary-label">إجمالي استهلاك المواد الخام</span>
                <span class="summary-value"><?php echo number_format((float)($rawTotals['total_out'] ?? 0), 3); ?> كجم</span>
            </div>
            <?php endif; ?>
            <div class="summary-card">
                <span class="summary-label">الصافي</span>
                <span class="summary-value text-success"><?php echo number_format($totalNet, 3); ?></span>
            </div>
            <div class="summary-card">
                <span class="summary-label">إجمالي الحركات</span>
                <span class="summary-value"><?php echo number_format($totalMovements); ?></span>
            </div>
            <div class="summary-card">
                <span class="summary-label">إجمالي التوريدات</span>
                <span class="summary-value text-primary"><?php echo number_format($supplyTotalQuantity, 3); ?> كجم</span>
            </div>
            <div class="summary-card">
                <span class="summary-label">عدد الموردين</span>
                <span class="summary-value"><?php echo $supplySuppliersCount; ?></span>
            </div>
            <?php if ($damageTotal > 0): ?>
            <div class="summary-card">
                <span class="summary-label">إجمالي التلفيات</span>
                <span class="summary-value text-danger"><?php echo number_format($damageTotal, 3); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- تفاصيل استهلاك التعبئة -->
        <?php if ($showPackagingReports && !empty($filteredPackagingItems)): ?>
        <div class="section-title">تفاصيل استهلاك أدوات التعبئة</div>
        <table>
            <thead>
                <tr>
                    <th>المادة</th>
                    <th class="text-center">الكمية الداخلة</th>
                    <th class="text-center">الكمية الخارجة</th>
                    <th class="text-center">الصافي</th>
                    <th class="text-center">عدد الحركات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filteredPackagingItems as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name'] ?? $item['material_name'] ?? '-'); ?></td>
                    <td class="text-center"><?php echo number_format((float)($item['total_in'] ?? 0), 3); ?></td>
                    <td class="text-center text-danger"><?php echo number_format((float)($item['total_out'] ?? 0), 3); ?></td>
                    <td class="text-center"><?php echo number_format((float)($item['net'] ?? 0), 3); ?></td>
                    <td class="text-center"><?php echo number_format((int)($item['movements'] ?? 0)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- تفاصيل استهلاك المواد الخام -->
        <?php if ($showRawReports && !empty($filteredRawItems)): ?>
        <div class="section-title">تفاصيل استهلاك المواد الخام</div>
        <table>
            <thead>
                <tr>
                    <th>المادة</th>
                    <th class="text-center">الكمية الداخلة</th>
                    <th class="text-center">الكمية الخارجة</th>
                    <th class="text-center">الصافي</th>
                    <th class="text-center">عدد الحركات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filteredRawItems as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name'] ?? $item['material_name'] ?? '-'); ?></td>
                    <td class="text-center"><?php echo number_format((float)($item['total_in'] ?? 0), 3); ?></td>
                    <td class="text-center text-danger"><?php echo number_format((float)($item['total_out'] ?? 0), 3); ?></td>
                    <td class="text-center"><?php echo number_format((float)($item['net'] ?? 0), 3); ?></td>
                    <td class="text-center"><?php echo number_format((int)($item['movements'] ?? 0)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- تفاصيل التوريدات -->
        <?php if (!empty($supplyLogs)): ?>
        <div class="section-title">تفاصيل توريدات المواد</div>
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>القسم</th>
                    <th>المورد</th>
                    <th class="text-center">الكمية</th>
                    <th>الوصف</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($supplyLogs as $supplyLog): ?>
                <tr>
                    <td>
                        <?php 
                        $recordedAt = $supplyLog['recorded_at'] ?? '';
                        echo htmlspecialchars(function_exists('formatDate') ? formatDate($recordedAt) : $recordedAt);
                        if (function_exists('formatTime')) {
                            echo ' ' . htmlspecialchars(formatTime($recordedAt));
                        }
                        ?>
                    </td>
                    <td>
                        <?php
                        $categoryKey = $supplyLog['material_category'] ?? '';
                        echo htmlspecialchars($supplyCategoryLabels[$categoryKey] ?? $categoryKey ?: '-');
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($supplyLog['supplier_name'] ?: '-'); ?></td>
                    <td class="text-center">
                        <strong><?php echo number_format((float)($supplyLog['quantity'] ?? 0), 3); ?></strong>
                        <small class="text-muted"><?php echo htmlspecialchars($supplyLog['unit'] ?? 'كجم'); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($supplyLog['details'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- تفاصيل التلفيات -->
        <?php if (!empty($damageLogs)): ?>
        <div class="section-title">تفاصيل التلفيات</div>
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>القسم</th>
                    <th>المادة</th>
                    <th class="text-center">الكمية التالفة</th>
                    <th>سبب التلف</th>
                    <th>المسجل</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($damageLogs as $damageLog): ?>
                <tr>
                    <td>
                        <?php 
                        $damageDate = $damageLog['recorded_at_raw'] ?? $damageLog['recorded_at'] ?? '';
                        if (!empty($damageDate)) {
                            echo htmlspecialchars($damageLog['recorded_date'] ?? (function_exists('formatDate') ? formatDate($damageDate) : $damageDate));
                            if (!empty($damageLog['recorded_time'])) {
                                echo ' ' . htmlspecialchars($damageLog['recorded_time']);
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($damageLog['category_label'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($damageLog['item_label'] ?? $damageLog['material_label'] ?? '-'); ?></td>
                    <td class="text-center text-danger">
                        <strong><?php echo number_format((float)($damageLog['quantity_raw'] ?? $damageLog['quantity'] ?? 0), 3); ?></strong>
                        <small class="text-muted"><?php echo htmlspecialchars($damageLog['unit'] ?? 'كجم'); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($damageLog['reason'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($damageLog['recorded_by'] ?? $damageLog['recorded_by_name'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="footer">
            <div>تم إنشاء هذا التقرير تلقائياً من نظام إدارة الإنتاج</div>
            <div style="margin-top: 10px;">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($companyName); ?>. جميع الحقوق محفوظة.</div>
        </div>
    </div>
</body>
</html>

