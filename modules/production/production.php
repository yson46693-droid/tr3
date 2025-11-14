<?php
/**
 * صفحة إدارة الإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/simple_telegram.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/batch_numbers.php';
require_once __DIR__ . '/../../includes/simple_barcode.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';
require_once __DIR__ . '/../../includes/production_helper.php';

requireRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

ensureProductTemplatesExtendedSchema($db);
syncAllUnifiedTemplatesToProductTemplates($db);

if (!function_exists('productionPageNormalizeText')) {
    function productionPageNormalizeText($value): string
    {
        $value = (string) $value;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

if (!function_exists('productionPageFilterItems')) {
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    function productionPageFilterItems(array $items, string $activeType, string $query, string $category): array
    {
        if ($activeType !== 'all' && $activeType !== $category) {
            return [];
        }

        $normalizedQuery = productionPageNormalizeText($query);
        if ($normalizedQuery === '') {
            return array_values($items);
        }

        $filtered = [];
        foreach ($items as $item) {
            $name = productionPageNormalizeText($item['name'] ?? '');
            $subCategory = productionPageNormalizeText($item['sub_category'] ?? '');
            if (strpos($name, $normalizedQuery) !== false || strpos($subCategory, $normalizedQuery) !== false) {
                $filtered[] = $item;
            }
        }

        return array_values($filtered);
    }
}

if (!function_exists('productionPageAggregateTotals')) {
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{total_out: float, total_in: float, net: float, movements: int}
     */
    function productionPageAggregateTotals(array $items): array
    {
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

if (!function_exists('productionPageBuildSubTotals')) {
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    function productionPageBuildSubTotals(array $items): array
    {
        $groups = [];
        $order = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['sub_category'] ?? 'غير مصنف'));
            if ($label === '') {
                $label = 'غير مصنف';
            }
            if (!isset($groups[$label])) {
                $groups[$label] = [
                    'label' => $label,
                    'total_out' => 0.0,
                    'total_in' => 0.0,
                    'net' => 0.0,
                ];
                $order[] = $label;
            }

            $groups[$label]['total_out'] += (float) ($item['total_out'] ?? 0);
            $groups[$label]['total_in'] += (float) ($item['total_in'] ?? 0);
        }

        $results = [];
        foreach ($order as $label) {
            $group = $groups[$label];
            $group['net'] = round($group['total_out'] - $group['total_in'], 3);
            $group['total_out'] = round($group['total_out'], 3);
            $group['total_in'] = round($group['total_in'], 3);
            $results[] = $group;
        }

        return $results;
    }
}

if (!function_exists('productionPageFormatDatePart')) {
    function productionPageFormatDatePart(?string $timestamp): string
    {
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
    function productionPageFormatTimePart(?string $timestamp): string
    {
        if (empty($timestamp)) {
            return '';
        }

        $ts = strtotime((string)$timestamp);
        if ($ts === false) {
            return '';
        }

        if (function_exists('formatTime')) {
            $formatted = formatTime($timestamp);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return date('H:i', $ts);
    }
}

if (!function_exists('productionPageFormatDateTimeLabel')) {
    function productionPageFormatDateTimeLabel(?string $timestamp): string
    {
        if (empty($timestamp)) {
            return '';
        }

        $date = productionPageFormatDatePart($timestamp);
        $time = productionPageFormatTimePart($timestamp);

        return trim($date . ' ' . $time);
    }
}

if (!function_exists('productionPageBuildDamagePayload')) {
    /**
     * @param array<string, mixed> $packagingDamage
     * @param array<string, mixed> $rawDamage
     * @return array{
     *     summary: array<int, array<string, mixed>>,
     *     logs: array<int, array<string, mixed>>,
     *     total: float,
     *     entries: int,
     *     latest_at: ?string,
     *     latest_label: string
     * }
     */
    function productionPageBuildDamagePayload(array $packagingDamage, array $rawDamage): array
    {
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
                'has_records' => $packagingEntries > 0,
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
                'has_records' => $categoryEntries > 0,
            ];
        }

        usort(
            $summaryRows,
            static function ($a, $b): int {
                $totalComparison = ($b['total'] ?? 0) <=> ($a['total'] ?? 0);
                if ($totalComparison !== 0) {
                    return $totalComparison;
                }

                return strcmp($a['label'] ?? '', $b['label'] ?? '');
            }
        );

        $entries = [];
        $latestTimestamp = null;

        foreach (($packagingDamage['logs'] ?? []) as $log) {
            $createdAt = $log['created_at'] ?? null;
            if ($createdAt && ($latestTimestamp === null || strtotime((string)$createdAt) > strtotime((string)$latestTimestamp))) {
                $latestTimestamp = $createdAt;
            }

            $materialName = trim((string)($log['material_label'] ?? $log['material_name'] ?? ''));
            if ($materialName === '') {
                $materialId = isset($log['material_id']) ? (int)$log['material_id'] : 0;
                $materialName = 'أداة #' . ($materialId > 0 ? $materialId : '?');
            }

            $entries[] = [
                'recorded_at_raw' => $createdAt,
                'recorded_date' => productionPageFormatDatePart($createdAt),
                'recorded_time' => productionPageFormatTimePart($createdAt),
                'category_label' => 'أدوات التعبئة',
                'item_label' => $materialName,
                'quantity_formatted' => number_format((float)($log['damaged_quantity'] ?? 0), 3),
                'quantity_raw' => (float)($log['damaged_quantity'] ?? 0),
                'unit' => trim((string)($log['unit'] ?? 'وحدة')),
                'reason' => trim((string)($log['reason'] ?? '')),
                'recorded_by' => trim((string)($log['recorded_by_name'] ?? '')),
                'supplier' => '',
                'source' => ($log['source_table'] ?? '') === 'products' ? 'مخزن المنتجات' : 'مخزن التعبئة',
            ];
        }

        foreach (($rawDamage['logs'] ?? []) as $log) {
            $createdAt = $log['created_at'] ?? null;
            if ($createdAt && ($latestTimestamp === null || strtotime((string)$createdAt) > strtotime((string)$latestTimestamp))) {
                $latestTimestamp = $createdAt;
            }

            $categoryKey = $log['material_category'] ?? '';
            $categoryLabel = $rawCategoryLabels[$categoryKey] ?? 'مواد خام';
            $itemName = trim((string)($log['item_label'] ?? 'مادة خام'));
            $variety = trim((string)($log['variety'] ?? ''));
            if ($variety !== '') {
                $itemName .= ' - ' . $variety;
            }

            $entries[] = [
                'recorded_at_raw' => $createdAt,
                'recorded_date' => productionPageFormatDatePart($createdAt),
                'recorded_time' => productionPageFormatTimePart($createdAt),
                'category_label' => 'قسم ' . $categoryLabel,
                'item_label' => $itemName,
                'quantity_formatted' => number_format((float)($log['quantity'] ?? 0), 3),
                'quantity_raw' => (float)($log['quantity'] ?? 0),
                'unit' => trim((string)($log['unit'] ?? 'كجم')),
                'reason' => trim((string)($log['reason'] ?? '')),
                'recorded_by' => trim((string)($log['recorded_by_name'] ?? '')),
                'supplier' => trim((string)($log['supplier_name'] ?? '')),
                'source' => 'مخزن المواد الخام',
            ];
        }

        usort(
            $entries,
            static function ($a, $b): int {
                return strcmp($b['recorded_at_raw'] ?? '', $a['recorded_at_raw'] ?? '');
            }
        );

        $latestLabel = productionPageFormatDateTimeLabel($latestTimestamp);

        $total = round(
            (float)($packagingDamage['total'] ?? 0) + (float)($rawDamage['total'] ?? 0),
            3
        );

        return [
            'summary' => $summaryRows,
            'logs' => $entries,
            'total' => $total,
            'entries' => count($entries),
            'latest_at' => $latestTimestamp,
            'latest_label' => $latestLabel,
        ];
    }
}

if (!function_exists('productionPageRenderDamageLogsTable')) {
    /**
     * @param array<int, array<string, mixed>> $logs
     */
    function productionPageRenderDamageLogsTable(array $logs, string $emptyMessage): void
    {
        if (empty($logs)) {
            echo '<div class="alert alert-light border border-danger-subtle text-muted mb-0">';
            echo '<i class="bi bi-inbox me-2"></i>' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8');
            echo '</div>';
            return;
        }

        echo '<div class="table-responsive dashboard-table-wrapper">';
        echo '<table class="table dashboard-table align-middle mb-0">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>التاريخ</th><th>القسم</th><th>المادة التالفة</th><th>الكمية</th><th>السبب</th><th>المصدر / المورد</th><th>المسجل</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($logs as $log) {
            $recordedDate = htmlspecialchars($log['recorded_date'] ?? '—', ENT_QUOTES, 'UTF-8');
            $recordedTimeRaw = trim((string)($log['recorded_time'] ?? ''));
            $recordedTime = htmlspecialchars($recordedTimeRaw, ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($log['category_label'] ?? '-', ENT_QUOTES, 'UTF-8');
            $item = htmlspecialchars($log['item_label'] ?? '-', ENT_QUOTES, 'UTF-8');
            $quantity = htmlspecialchars($log['quantity_formatted'] ?? '0', ENT_QUOTES, 'UTF-8');
            $unit = htmlspecialchars($log['unit'] ?? '', ENT_QUOTES, 'UTF-8');
            $reasonRaw = trim((string)($log['reason'] ?? ''));
            $supplierRaw = trim((string)($log['supplier'] ?? ''));
            $source = htmlspecialchars($log['source'] ?? '-', ENT_QUOTES, 'UTF-8');
            $recordedByRaw = trim((string)($log['recorded_by'] ?? ''));
            $recordedBy = $recordedByRaw !== '' ? htmlspecialchars($recordedByRaw, ENT_QUOTES, 'UTF-8') : 'غير محدد';

            echo '<tr>';
            echo '<td><div class="fw-semibold">' . $recordedDate . '</div>';
            if ($recordedTimeRaw !== '') {
                echo '<div class="text-muted small">' . $recordedTime . '</div>';
            }
            echo '</td>';
            echo '<td>' . $category . '</td>';
            echo '<td>' . $item . '</td>';
            echo '<td><span class="fw-semibold text-danger">' . $quantity . '</span> <span class="text-muted small">' . $unit . '</span></td>';
            if ($reasonRaw !== '') {
                echo '<td>' . htmlspecialchars($reasonRaw, ENT_QUOTES, 'UTF-8') . '</td>';
            } else {
                echo '<td><span class="text-muted">—</span></td>';
            }
            echo '<td>';
            if ($supplierRaw !== '') {
                echo '<div>' . htmlspecialchars($supplierRaw, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            echo '<div class="text-muted small">' . $source . '</div>';
            echo '</td>';
            echo '<td>' . $recordedBy . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
}

/**
 * التحقق من توفر المكونات المستخدمة في صناعة المنتج
 */
function checkMaterialsAvailability($db, $templateId, $productionQuantity, array $materialSuppliers = []) {
    $missingMaterials = [];
    $insufficientMaterials = [];
    
    // 1. التحقق من مواد التعبئة
    $packagingNameExpression = getColumnSelectExpression('product_template_packaging', 'packaging_name');
    $packagingMaterials = $db->query(
        "SELECT id, packaging_material_id, quantity_per_unit, {$packagingNameExpression}
         FROM product_template_packaging 
         WHERE template_id = ?",
        [$templateId]
    );
    
    foreach ($packagingMaterials as $packaging) {
        $packagingId = $packaging['packaging_material_id'] ?? null;
        $packagingTemplateRowId = isset($packaging['id']) ? (int)$packaging['id'] : 0;
        $requiredQuantity = floatval($packaging['quantity_per_unit']) * $productionQuantity;
        $packagingName = $packaging['packaging_name'] ?? 'مادة تعبئة';

        $supplierKeys = [];
        if ($packagingId) {
            $supplierKeys[] = 'pack_' . $packagingId;
        }
        if ($packagingTemplateRowId > 0) {
            $supplierKeys[] = 'pack_' . $packagingTemplateRowId;
        }
        if (!empty($packagingName)) {
            $normalizedNameKey = mb_strtolower(trim((string)$packagingName), 'UTF-8');
            if ($normalizedNameKey !== '') {
                $supplierKeys[] = 'pack_' . md5($normalizedNameKey);
            }
        }

        $hasSupplierSelection = false;
        foreach ($supplierKeys as $supplierKey) {
            if (isset($materialSuppliers[$supplierKey]) && intval($materialSuppliers[$supplierKey]) > 0) {
                $hasSupplierSelection = true;
                break;
            }
        }

        $bestAvailability = null;
        $availabilityChecked = false;

        $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
        if (!empty($packagingTableCheck)) {
            $packagingMaterial = null;
            if ($packagingId) {
                $packagingMaterial = $db->queryOne(
                    "SELECT id, name, quantity FROM packaging_materials WHERE id = ?",
                    [$packagingId]
                );
                if (!$packagingMaterial) {
                    $packagingMaterial = $db->queryOne(
                        "SELECT id, name, quantity FROM packaging_materials WHERE product_id = ?",
                        [$packagingId]
                    );
                }
            }
            if (!$packagingMaterial && !empty($packagingName)) {
                $packagingMaterial = $db->queryOne(
                    "SELECT id, name, quantity FROM packaging_materials WHERE name = ? LIMIT 1",
                    [$packagingName]
                );
            }

            if ($packagingMaterial) {
                $availabilityChecked = true;
                $availableQuantity = floatval($packagingMaterial['quantity'] ?? 0);
                $candidateName = $packagingMaterial['name'] ?? $packagingName;
                $bestAvailability = [
                    'available' => $availableQuantity,
                    'name' => $candidateName
                ];
            }
        }

        $product = null;
        if ($packagingId) {
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE id = ? AND status = 'active'",
                [$packagingId]
            );
        }
        if (!$product && !empty($packagingName)) {
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$packagingName]
            );
        }

        if ($product) {
            $availabilityChecked = true;
            $availableQuantity = floatval($product['quantity'] ?? 0);
            $candidateName = $product['name'] ?? $packagingName;
            if ($bestAvailability === null || $availableQuantity > $bestAvailability['available']) {
                $bestAvailability = [
                    'available' => $availableQuantity,
                    'name' => $candidateName
                ];
            }
        }

        if ($bestAvailability !== null && $bestAvailability['available'] >= $requiredQuantity) {
            continue;
        }

        if ($hasSupplierSelection) {
            continue;
        }

        if ($availabilityChecked) {
            $insufficientMaterials[] = [
                'name' => $bestAvailability['name'] ?? $packagingName,
                'required' => $requiredQuantity,
                'available' => max(0, $bestAvailability['available'] ?? 0),
                'type' => 'مواد التعبئة',
                'unit' => 'قطعة'
            ];
        } else {
            $missingMaterials[] = [
                'name' => $packagingName !== '' ? $packagingName : 'مادة تعبئة غير معروفة',
                'type' => 'مواد التعبئة'
            ];
        }
    }
    
    // 2. التحقق من المواد الخام
    $templateRow = $db->queryOne(
        "SELECT honey_quantity, details_json 
         FROM product_templates 
         WHERE id = ?",
        [$templateId]
    );

    $rawMaterialsDetails = [];
    if (!empty($templateRow['details_json'])) {
        try {
            $decodedDetails = json_decode($templateRow['details_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!empty($decodedDetails['raw_materials']) && is_array($decodedDetails['raw_materials'])) {
                foreach ($decodedDetails['raw_materials'] as $rawDetail) {
                    if (!is_array($rawDetail)) {
                        continue;
                    }
                    $rawNameKey = '';
                    if (!empty($rawDetail['name'])) {
                        $rawNameKey = mb_strtolower(trim((string)$rawDetail['name']), 'UTF-8');
                    } elseif (!empty($rawDetail['material_name'])) {
                        $rawNameKey = mb_strtolower(trim((string)$rawDetail['material_name']), 'UTF-8');
                    }
                    if ($rawNameKey !== '') {
                        $rawMaterialsDetails[$rawNameKey] = $rawDetail;
                    }
                }
            }
        } catch (Throwable $decodeError) {
            error_log('checkMaterialsAvailability: failed decoding details_json -> ' . $decodeError->getMessage());
        }
    }

    $normalizeMaterialType = static function ($type, string $materialName): string {
        $normalizedType = '';
        if (is_string($type)) {
            $normalizedType = mb_strtolower(trim($type), 'UTF-8');
        }

        if ($normalizedType === '') {
            $nameNormalized = mb_strtolower(trim($materialName), 'UTF-8');

            if ($nameNormalized !== '') {
                if (mb_strpos($nameNormalized, 'زيت زيتون') !== false || mb_strpos($nameNormalized, 'olive oil') !== false) {
                    return 'olive_oil';
                }
                if (mb_strpos($nameNormalized, 'عسل') !== false || mb_strpos($nameNormalized, 'honey') !== false) {
                    return 'honey';
                }
                if (mb_strpos($nameNormalized, 'شمع') !== false || mb_strpos($nameNormalized, 'beeswax') !== false) {
                    return 'beeswax';
                }
                if (mb_strpos($nameNormalized, 'مشتق') !== false || mb_strpos($nameNormalized, 'derivative') !== false) {
                    return 'derivatives';
                }
                if (mb_strpos($nameNormalized, 'مكسرات') !== false || mb_strpos($nameNormalized, 'nuts') !== false) {
                    return 'nuts';
                }
            }
        } elseif (in_array($normalizedType, ['honey_raw', 'honey_filtered', 'honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts'], true)) {
            return $normalizedType;
        }

        return $normalizedType !== '' ? $normalizedType : 'other';
    };

    $normalizeUnitLabel = static function (?string $unit): string {
        if (!is_string($unit)) {
            return '';
        }

        $normalized = mb_strtolower(trim($unit), 'UTF-8');
        if ($normalized === '') {
            return '';
        }

        $map = [
            'كجم' => 'kg',
            'كغ' => 'kg',
            'كيلوجرام' => 'kg',
            'كيلوغرام' => 'kg',
            'كيلو' => 'kg',
            'kg' => 'kg',
            'kilogram' => 'kg',
            'جرام' => 'kg',
            'جم' => 'kg',
            'غرام' => 'kg',
            'g' => 'kg',
            'gram' => 'kg',
            'لتر' => 'kg',
            'لترًا' => 'kg',
            'lt' => 'kg',
            'liter' => 'kg',
            'l' => 'kg',
            'مل' => 'kg',
            'مليلتر' => 'kg',
            'ملي لتر' => 'kg',
            'ميليلتر' => 'kg',
            'ml' => 'kg',
            'قطعة' => 'piece',
            'قطعه' => 'piece',
            'pcs' => 'piece',
            'حبة' => 'piece',
            'حبه' => 'piece',
        ];

        return $map[$normalized] ?? $normalized;
    };

    $convertQuantityUnit = static function (float $quantity, ?string $fromUnit, ?string $toUnit) use ($normalizeUnitLabel): float {
        if ($quantity === 0.0) {
            return 0.0;
        }

        $rawFrom = '';
        if (is_string($fromUnit)) {
            $rawFrom = mb_strtolower(trim($fromUnit), 'UTF-8');
        }

        $rawTo = '';
        if (is_string($toUnit)) {
            $rawTo = mb_strtolower(trim($toUnit), 'UTF-8');
        }

        $fromNormalized = $normalizeUnitLabel($fromUnit);
        $toNormalized = $normalizeUnitLabel($toUnit);

        if ($fromNormalized === '') {
            $fromNormalized = 'kg';
        }
        if ($toNormalized === '') {
            $toNormalized = 'kg';
        }

        // Legacy factors to normalize historical values to kilograms.
        $legacyFactors = [
            '' => 1.0,
            'كجم' => 1.0,
            'كغ' => 1.0,
            'كيلوجرام' => 1.0,
            'كيلوغرام' => 1.0,
            'كيلو' => 1.0,
            'kg' => 1.0,
            'kilogram' => 1.0,
            'جرام' => 0.001,
            'جم' => 0.001,
            'غرام' => 0.001,
            'g' => 0.001,
            'gram' => 0.001,
            'لتر' => 1.0,
            'لترًا' => 1.0,
            'lt' => 1.0,
            'liter' => 1.0,
            'l' => 1.0,
            'مل' => 0.001,
            'مليلتر' => 0.001,
            'ملي لتر' => 0.001,
            'ميليلتر' => 0.001,
            'ml' => 0.001,
        ];

        // Convert legacy values into kilograms.
        if ($fromNormalized === 'kg') {
            $factor = $legacyFactors[$rawFrom] ?? 1.0;
            $quantity *= $factor;
        }

        // If the target representation is also kilograms, return immediately.
        if ($toNormalized === 'kg') {
            return $quantity;
        }

        // Fallback for other unit families (e.g., pieces) – no conversion applied.
        return $quantity;
    };

    $checkStock = static function (string $table, string $column, ?int $supplierId = null) use ($db): float {
        $sql = "SELECT SUM({$column}) AS total_quantity FROM {$table}";
        $params = [];
        if ($supplierId) {
            $sql .= " WHERE supplier_id = ?";
            $params[] = $supplierId;
        }
        $stockRow = $db->queryOne($sql, $params);
        return (float)($stockRow['total_quantity'] ?? 0);
    };

    $resolveSpecialStock = static function (?string $materialType, ?int $supplierId, string $materialName) use ($db, $checkStock, $normalizeMaterialType, $normalizeUnitLabel): array {
        $resolved = false;
        $availableQuantity = 0.0;
        $availableUnit = '';
        $normalizedType = $normalizeMaterialType($materialType, $materialName);

        switch ($normalizedType) {
            case 'honey_raw':
            case 'honey_filtered':
            case 'honey':
                $honeyStockExists = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
                if (!empty($honeyStockExists)) {
                    $column = ($normalizedType === 'honey_raw') ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                    $availableQuantity = $checkStock('honey_stock', $column, $supplierId);
                    $resolved = true;
                    $availableUnit = 'kg';
                }
                break;
            case 'olive_oil':
                $oliveTableExists = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
                if (!empty($oliveTableExists)) {
                    $availableQuantity = $checkStock('olive_oil_stock', 'quantity', $supplierId);
                    $resolved = true;
                    $availableUnit = 'l';
                }
                break;
            case 'beeswax':
                $beeswaxTableExists = $db->queryOne("SHOW TABLES LIKE 'beeswax_stock'");
                if (!empty($beeswaxTableExists)) {
                    $availableQuantity = $checkStock('beeswax_stock', 'weight', $supplierId);
                    $resolved = true;
                    $availableUnit = 'kg';
                }
                break;
            case 'derivatives':
                $derivativesTableExists = $db->queryOne("SHOW TABLES LIKE 'derivatives_stock'");
                if (!empty($derivativesTableExists)) {
                    $availableQuantity = $checkStock('derivatives_stock', 'weight', $supplierId);
                    $resolved = true;
                    $availableUnit = 'kg';
                }
                break;
            case 'nuts':
                $nutsTableExists = $db->queryOne("SHOW TABLES LIKE 'nuts_stock'");
                if (!empty($nutsTableExists)) {
                    $availableQuantity = $checkStock('nuts_stock', 'quantity', $supplierId);
                    $resolved = true;
                    $availableUnit = 'kg';
                }
                break;
            default:
                if ($supplierId) {
                    $genericStockTableExists = $db->queryOne("SHOW TABLES LIKE 'raw_materials'");
                    if (!empty($genericStockTableExists)) {
                        $stockRow = $db->queryOne(
                            "SELECT SUM(quantity) AS total_quantity 
                             FROM raw_materials 
                             WHERE supplier_id = ?",
                            [$supplierId]
                        );
                        $availableQuantity = (float)($stockRow['total_quantity'] ?? 0);
                        $resolved = true;
                        $availableUnit = $normalizeUnitLabel(null);
                    }
                }
                break;
        }

        return [
            'resolved' => $resolved,
            'quantity' => $availableQuantity,
            'unit' => $availableUnit,
        ];
    };

    $rawMaterials = $db->query(
        "SELECT material_name, quantity_per_unit, unit 
         FROM product_template_raw_materials 
         WHERE template_id = ?",
        [$templateId]
    );
    
    foreach ($rawMaterials as $raw) {
        $materialName = $raw['material_name'];
        $requiredQuantity = floatval($raw['quantity_per_unit']) * $productionQuantity;
        $normalizedName = mb_strtolower(trim((string)$materialName), 'UTF-8');
        $rawDetail = $rawMaterialsDetails[$normalizedName] ?? null;
        $materialTypeMeta = $rawDetail['type'] ?? null;
        $materialSupplierMeta = isset($rawDetail['supplier_id']) ? (int)$rawDetail['supplier_id'] : null;
        $materialUnit = $raw['unit'] ?? ($rawDetail['unit'] ?? 'كجم');
        $materialUnitNormalized = $normalizeUnitLabel($materialUnit);
        
        // البحث عن المادة في جدول المنتجات
        $product = $db->queryOne(
            "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
            [$materialName]
        );
        
        if ($product) {
            $availableQuantity = floatval($product['quantity'] ?? 0);

            if ($availableQuantity < $requiredQuantity) {
                $specialStock = $resolveSpecialStock($materialTypeMeta, $materialSupplierMeta, $materialName);
                if ($specialStock['resolved']) {
                    $availableFromSpecial = $specialStock['quantity'];
                    $availableFromSpecial = $convertQuantityUnit(
                        $availableFromSpecial,
                        $specialStock['unit'] ?? '',
                        $materialUnitNormalized
                    );
                    $availableQuantity += $availableFromSpecial;
                }
            }

            if ($availableQuantity < $requiredQuantity) {
                $insufficientMaterials[] = [
                    'name' => $materialName,
                    'required' => $requiredQuantity,
                    'available' => $availableQuantity,
                    'type' => 'مواد خام',
                    'unit' => $materialUnit
                ];
            }
        } else {
            $specialStock = $resolveSpecialStock($materialTypeMeta, $materialSupplierMeta, $materialName);
            if ($specialStock['resolved']) {
                $availableQuantity = $specialStock['quantity'];
                $availableQuantity = $convertQuantityUnit(
                    $availableQuantity,
                    $specialStock['unit'] ?? '',
                    $materialUnitNormalized
                );
                if ($availableQuantity < $requiredQuantity) {
                    $insufficientMaterials[] = [
                        'name' => $materialName,
                        'required' => $requiredQuantity,
                        'available' => $availableQuantity,
                        'type' => 'مواد خام',
                        'unit' => $materialUnit
                    ];
                }
            } else {
                $missingMaterials[] = [
                    'name' => $materialName,
                    'type' => 'مواد خام',
                    'unit' => $materialUnit
                ];
            }
        }
    }
    
    // 3. التحقق من العسل (من القالب)
    $honeyQuantity = floatval($templateRow['honey_quantity'] ?? 0);
    if ($honeyQuantity > 0) {
        $requiredHoney = $honeyQuantity * $productionQuantity;
        
        // البحث عن العسل في جدول honey_stock (العسل المصفى فقط)
        $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
        if ($honeyStockTableCheck) {
            $honeyStock = $db->query(
                "SELECT hs.*, s.name as supplier_name 
                 FROM honey_stock hs
                 LEFT JOIN suppliers s ON hs.supplier_id = s.id
                 WHERE hs.filtered_honey_quantity > 0
                 ORDER BY hs.filtered_honey_quantity DESC"
            );
            
            $totalHoneyAvailable = 0;
            $honeyDetails = [];
            foreach ($honeyStock as $honey) {
                $available = floatval($honey['filtered_honey_quantity'] ?? 0);
                $totalHoneyAvailable += $available;
                if ($available > 0) {
                    $honeyDetails[] = $honey['honey_variety'] . ' (' . $honey['supplier_name'] . '): ' . number_format($available, 2) . ' كجم';
                }
            }
            
            if ($totalHoneyAvailable < $requiredHoney) {
                $insufficientMaterials[] = [
                    'name' => 'عسل مصفى',
                    'required' => $requiredHoney,
                    'available' => $totalHoneyAvailable,
                    'type' => 'عسل',
                    'unit' => 'كجم',
                    'details' => implode(' | ', $honeyDetails)
                ];
            }
        } else {
            // البحث في جدول المنتجات كبديل
            $honeyProducts = $db->query(
                "SELECT id, name, quantity FROM products 
                 WHERE (name LIKE '%عسل%' OR category = 'honey' OR category = 'raw_material') 
                 AND status = 'active'
                 ORDER BY quantity DESC"
            );
            
            $totalHoneyAvailable = 0;
            foreach ($honeyProducts as $honey) {
                $totalHoneyAvailable += floatval($honey['quantity'] ?? 0);
            }
            
            if ($totalHoneyAvailable < $requiredHoney) {
                $insufficientMaterials[] = [
                    'name' => 'عسل',
                    'required' => $requiredHoney,
                    'available' => $totalHoneyAvailable,
                    'type' => 'عسل',
                    'unit' => 'كجم'
                ];
            }
        }
    }
    
    // بناء رسالة الخطأ
    $errorMessages = [];
    
    if (!empty($missingMaterials)) {
        $missingNames = array_map(function($m) {
            return $m['name'] . ' (' . $m['type'] . ')';
        }, $missingMaterials);
        $errorMessages[] = 'مواد غير موجودة في المخزون: ' . implode(', ', $missingNames);
    }
    
    if (!empty($insufficientMaterials)) {
        $insufficientDetails = [];
        foreach ($insufficientMaterials as $mat) {
            $unit = $mat['unit'] ?? '';
            $insufficientDetails[] = sprintf(
                '%s (%s): مطلوب %s %s، متوفر %s %s',
                $mat['name'],
                $mat['type'],
                number_format($mat['required'], 2),
                $unit,
                number_format($mat['available'], 2),
                $unit
            );
        }
        $errorMessages[] = 'مواد غير كافية: ' . implode(' | ', $insufficientDetails);
    }
    
    if (!empty($errorMessages)) {
        return [
            'available' => false,
            'message' => implode(' | ', $errorMessages),
            'missing' => $missingMaterials,
            'insufficient' => $insufficientMaterials
        ];
    }
    
    return [
        'available' => true,
        'message' => 'جميع المكونات متوفرة'
    ];
}

// إنشاء جدول batch_numbers إذا لم يكن موجودًا مسبقًا
$hasBatchNumbersTable = false;
try {
    $batchTableCheck = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
    if (empty($batchTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `batch_numbers` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `batch_number` varchar(100) NOT NULL,
              `product_id` int(11) NOT NULL,
              `production_id` int(11) DEFAULT NULL,
              `production_date` date NOT NULL,
              `honey_supplier_id` int(11) DEFAULT NULL,
              `packaging_materials` text DEFAULT NULL COMMENT 'JSON array of packaging material IDs',
              `packaging_supplier_id` int(11) DEFAULT NULL,
              `workers` text DEFAULT NULL COMMENT 'JSON array of worker IDs',
              `quantity` int(11) NOT NULL DEFAULT 1,
              `status` enum('in_production','completed','in_stock','sold','expired') DEFAULT 'in_production',
              `expiry_date` date DEFAULT NULL,
              `notes` text DEFAULT NULL,
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `batch_number` (`batch_number`),
              KEY `product_id` (`product_id`),
              KEY `production_id` (`production_id`),
              KEY `production_date` (`production_date`),
              KEY `status` (`status`),
              KEY `created_by` (`created_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إضافة العمود all_suppliers إذا لم يكن موجودًا مسبقًا
    $allSuppliersColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'all_suppliers'");
    if (empty($allSuppliersColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `all_suppliers` TEXT DEFAULT NULL COMMENT 'JSON array of all suppliers with their materials' 
                AFTER `packaging_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding all_suppliers column: " . $e->getMessage());
        }
    }
    
    // إضافة العمود honey_variety إذا لم يكن موجودًا مسبقًا
    $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'honey_variety'");
    if (empty($honeyVarietyColumnCheck)) {
        try {
            $db->execute("
                ALTER TABLE `batch_numbers` 
                ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'Honey variety used' 
                AFTER `honey_supplier_id`
            ");
        } catch (Exception $e) {
            error_log("Error adding honey_variety column: " . $e->getMessage());
        }
    }
    $hasBatchNumbersTable = true;
} catch (Exception $e) {
    error_log("Batch numbers table creation error: " . $e->getMessage());
    $hasBatchNumbersTable = false;
}

$hasFinishedProductsTable = false;
try {
    $finishedProductsTableCheck = $db->queryOne("SHOW TABLES LIKE 'finished_products'");
    if (!empty($finishedProductsTableCheck)) {
        $hasFinishedProductsTable = true;
    }
} catch (Exception $e) {
    error_log("Finished products table check error: " . $e->getMessage());
    $hasFinishedProductsTable = false;
}

// التحقق من وجود عمود date أو production_date
$dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$hasDateColumn = !empty($dateColumnCheck);
$hasProductionDateColumn = !empty($productionDateColumnCheck);
$dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

// التحقق من وجود عمود user_id أو worker_id
$userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
$workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
$hasUserIdColumn = !empty($userIdColumnCheck);
$hasWorkerIdColumn = !empty($workerIdColumnCheck);
$userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

// معالجة الطلبات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // منع تكرار الإرسال باستخدام رمز CSRF
    $submitToken = $_POST['submit_token'] ?? '';
    $sessionToken = $_SESSION['last_submit_token'] ?? '';
    
    if ($submitToken && $submitToken === $sessionToken) {
        // تم إرسال هذا النموذج بالفعل - تجاهل التكرار
        $error = 'تم معالجة هذا الطلب من قبل. يرجى عدم إعادة تحميل الصفحة بعد الإرسال.';
        error_log("Duplicate form submission detected: token={$submitToken}, action={$action}");
    } elseif ($action === 'add_production') {
        // حفظ الرمز لمنع الإرسال المتكرر
        $_SESSION['last_submit_token'] = $submitToken;
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = 'kg';
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        
        // تحديد معرف المستخدم المسؤول عن الإنتاج وفقًا للدور
        $selectedUserId = intval($_POST['user_id'] ?? 0);
        if ($currentUser['role'] === 'production' && $selectedUserId <= 0) {
            $selectedUserId = $currentUser['id'];
        } elseif ($selectedUserId <= 0) {
            $error = 'يجب اختيار العامل';
        }
        
        if (empty($productId) || $productId <= 0) {
            $error = 'يجب اختيار المنتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة';
        } elseif ($selectedUserId <= 0) {
            $error = 'يجب اختيار العامل';
        } else {
            // بناء الاستعلام ديناميكيًا بحسب الأعمدة المتاحة
            $columns = ['product_id', 'quantity'];
            $values = [$productId, $quantity];
            $placeholders = ['?', '?'];
            
            // إضافة عمود التاريخ
            $columns[] = $dateColumn;
            $values[] = $productionDate;
            $placeholders[] = '?';
            
            // إضافة عمود المستخدم أو العامل إن وجد
            if ($userIdColumn) {
                $columns[] = $userIdColumn;
                $values[] = $selectedUserId;
                $placeholders[] = '?';
            }
            
            // إضافة بيانات المواد المستخدمة إن وُجدت
            if ($materialsUsed) {
                $columns[] = 'materials_used';
                $values[] = $materialsUsed;
                $placeholders[] = '?';
            }
            
            // إضافة الملاحظات
            if ($notes) {
                $columns[] = 'notes';
                $values[] = $notes;
                $placeholders[] = '?';
            }
            
            // إضافة الحالة (افتراضيًا pending)
            $columns[] = 'status';
            $values[] = 'pending';
            $placeholders[] = '?';
            
            $sql = "INSERT INTO production (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            try {
                $result = $db->execute($sql, $values);
                
                logAudit($currentUser['id'], 'add_production', 'production', $result['insert_id'], null, [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ]);
                
                // منع التكرار باستخدام إعادة التوجيه
                $successMessage = 'تم إضافة سجل الإنتاج بنجاح';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ في إضافة سجل الإنتاج: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_production') {
        $_SESSION['last_submit_token'] = $submitToken;
        $productionId = intval($_POST['production_id'] ?? 0);
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = 'kg';
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');
        $materialsUsed = trim($_POST['materials_used'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        
        if ($productionId <= 0) {
            $error = 'معرف الإنتاج غير صحيح';
        } elseif ($productId <= 0) {
            $error = 'يجب اختيار المنتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة';
        } else {
            // بناء الاستعلام ديناميكيًا بحسب الأعمدة المتاحة
            $setParts = ['product_id = ?', 'quantity = ?'];
            $values = [$productId, $quantity];
            
            // تحديث عمود التاريخ
            $setParts[] = "$dateColumn = ?";
            $values[] = $productionDate;
            
            // تحديث بيانات المواد المستخدمة إذا توفرت
            if ($materialsUsed !== '') {
                $setParts[] = 'materials_used = ?';
                $values[] = $materialsUsed;
            }
            
            // تحديث الملاحظات
            if ($notes !== '') {
                $setParts[] = 'notes = ?';
                $values[] = $notes;
            }
            
            // تحديث الحالة
            $setParts[] = 'status = ?';
            $values[] = $status;
            
            $values[] = $productionId;
            
            $sql = "UPDATE production SET " . implode(', ', $setParts) . " WHERE id = ?";
            
            try {
                $db->execute($sql, $values);
                
                logAudit($currentUser['id'], 'update_production', 'production', $productionId, null, [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'status' => $status
                ]);
                
                // منع التكرار باستخدام إعادة التوجيه
                $successMessage = 'تم تحديث سجل الإنتاج بنجاح';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ في تحديث سجل الإنتاج: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_production') {
        $_SESSION['last_submit_token'] = $submitToken;
        $productionId = intval($_POST['production_id'] ?? 0);
        
        if ($productionId <= 0) {
            $error = 'معرف الإنتاج غير صحيح';
        } else {
            try {
                // حذف بيانات الإنتاج المرتبطة أولًا
                $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);
                
                // حذف سجل الإنتاج
                $db->execute("DELETE FROM production WHERE id = ?", [$productionId]);
                
                logAudit($currentUser['id'], 'delete_production', 'production', $productionId, null, null);
                
                // منع التكرار عن طريق إعادة التوجيه
                $successMessage = 'تم حذف سجل الإنتاج بنجاح';
                $redirectParams = ['page' => 'production'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            } catch (Exception $e) {
                $error = 'حدث خطأ في حذف سجل الإنتاج: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'create_from_template') {
        $_SESSION['last_submit_token'] = $submitToken;
        $templateId = intval($_POST['template_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $productionDate = $_POST['production_date'] ?? date('Y-m-d');
        
        if ($templateId <= 0) {
            $error = 'يجب اختيار قالب منتج';
        } elseif ($quantity <= 0) {
            $error = 'يجب إدخال كمية صحيحة (أكبر من 0)';
        } else {
            try {
                $db->beginTransaction();
                
                $materialSuppliersInput = $_POST['material_suppliers'] ?? [];
                $materialSuppliers = [];
                if (is_array($materialSuppliersInput)) {
                    foreach ($materialSuppliersInput as $key => $value) {
                        $materialSuppliers[$key] = intval($value);
                    }
                }

                $materialHoneyVarietiesInput = $_POST['material_honey_varieties'] ?? [];
                $materialHoneyVarieties = [];
                if (is_array($materialHoneyVarietiesInput)) {
                    foreach ($materialHoneyVarietiesInput as $key => $value) {
                        $cleanValue = trim((string)$value);
                        if ($cleanValue !== '') {
                            $cleanValue = mb_substr($cleanValue, 0, 120, 'UTF-8');
                        }
                        $materialHoneyVarieties[$key] = $cleanValue;
                    }
                }

                $extraSupplierIdsInput = $_POST['extra_supplier_ids'] ?? [];
                $extraSupplierIds = [];
                if (is_array($extraSupplierIdsInput)) {
                    foreach ($extraSupplierIdsInput as $value) {
                        $supplierIdValue = intval($value);
                        if ($supplierIdValue > 0) {
                            $extraSupplierIds[$supplierIdValue] = true;
                        }
                    }
                    $extraSupplierIds = array_keys($extraSupplierIds);
                }

                if (empty($materialSuppliers)) {
                    throw new Exception('يرجى اختيار المورد المحاسب لهذه المادة قبل إنشاء التشغيلة.');
                }

                $templateMode = $_POST['template_mode'] ?? 'advanced';
                if ($templateMode !== 'advanced') {
                    $templateMode = 'advanced';
                }
                $templateType = trim($_POST['template_type'] ?? 'legacy');

                $template = $db->queryOne(
                    "SELECT pt.*, pr.id as product_id, pr.name as product_name
                     FROM product_templates pt
                     LEFT JOIN products pr ON pt.product_name = pr.name
                     WHERE pt.id = ?",
                    [$templateId]
                );

                if (!$template) {
                    throw new Exception('القالب غير موجود');
                }

                $materialsCheck = checkMaterialsAvailability($db, $templateId, $quantity, $materialSuppliers);
                if (!$materialsCheck['available']) {
                    throw new Exception('المكونات غير متوفرة: ' . $materialsCheck['message']);
                }

                $templateType = $template['template_type'] ?? $templateType;
                $packagingItems = $db->query(
                    "SELECT id, packaging_material_id, packaging_name, quantity_per_unit
                     FROM product_template_packaging
                     WHERE template_id = ?",
                    [$templateId]
                );
                $rawMaterials = $db->query(
                    "SELECT id, material_name, quantity_per_unit, unit
                     FROM product_template_raw_materials
                     WHERE template_id = ?",
                    [$templateId]
                );

                $normalizeRawName = static function ($value): string {
                    if (!is_string($value)) {
                        $value = (string) $value;
                    }
                    $trimmed = trim($value);
                    if ($trimmed === '') {
                        return '';
                    }
                    $normalizedWhitespace = preg_replace('/\s+/u', ' ', $trimmed);
                    return function_exists('mb_strtolower')
                        ? mb_strtolower($normalizedWhitespace, 'UTF-8')
                        : strtolower($normalizedWhitespace);
                };

                $templateRawDetailsById = [];
                $templateRawDetailsByName = [];
                if (!empty($template['details_json'])) {
                    $decodedTemplateDetails = json_decode((string) $template['details_json'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedTemplateDetails)) {
                        $rawDetailsList = $decodedTemplateDetails['raw_materials'] ?? [];
                        if (is_array($rawDetailsList)) {
                            foreach ($rawDetailsList as $rawDetail) {
                                if (!is_array($rawDetail)) {
                                    continue;
                                }
                                if (isset($rawDetail['template_item_id'])) {
                                    $templateRawDetailsById[(int) $rawDetail['template_item_id']] = $rawDetail;
                                }
                                if (isset($rawDetail['id'])) {
                                    $templateRawDetailsById[(int) $rawDetail['id']] = $rawDetail;
                                }
                                $detailName = '';
                                if (!empty($rawDetail['name'])) {
                                    $detailName = (string) $rawDetail['name'];
                                } elseif (!empty($rawDetail['material_name'])) {
                                    $detailName = (string) $rawDetail['material_name'];
                                }
                                $normalizedDetailName = $normalizeRawName($detailName);
                                if ($normalizedDetailName !== '' && !isset($templateRawDetailsByName[$normalizedDetailName])) {
                                    $templateRawDetailsByName[$normalizedDetailName] = $rawDetail;
                                }
                            }
                        }
                    }
                }

                $packagingIdsMap = [];
                $packagingTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
                $packagingProductColumnExists = $packagingTableExists && productionColumnExists('packaging_materials', 'product_id');
                $packagingAliasColumnExists = $packagingTableExists && productionColumnExists('packaging_materials', 'alias');
                $packagingSelectColumns = $packagingProductColumnExists ? 'id, name, unit, product_id' : 'id, name, unit';
                $packagingNameCache = [];
                $resolvePackagingByName = static function (string $name) use ($db, &$packagingNameCache, $packagingSelectColumns, $packagingAliasColumnExists) {
                    $trimmedName = trim($name);
                    if ($trimmedName === '') {
                        return null;
                    }

                    $normalizedSource = function_exists('mb_strtolower')
                        ? mb_strtolower(preg_replace('/\s+/u', ' ', $trimmedName), 'UTF-8')
                        : strtolower(preg_replace('/\s+/', ' ', $trimmedName));

                    if (!array_key_exists($normalizedSource, $packagingNameCache)) {
                        $params = [$trimmedName];
                        $query = "SELECT {$packagingSelectColumns} FROM packaging_materials WHERE name = ?";
                        if ($packagingAliasColumnExists) {
                            $query .= " OR alias = ?";
                            $params[] = $trimmedName;
                        }
                        $query .= " LIMIT 1";
                        $match = $db->queryOne($query, $params);

                        if (!$match) {
                            $fallbackParams = [$trimmedName];
                            $fallbackQuery = "SELECT {$packagingSelectColumns} 
                                FROM packaging_materials 
                                WHERE LOWER(REPLACE(name, ' ', '')) = LOWER(REPLACE(?, ' ', ''))";
                            if ($packagingAliasColumnExists) {
                                $fallbackQuery .= " OR LOWER(REPLACE(IFNULL(alias, ''), ' ', '')) = LOWER(REPLACE(?, ' ', ''))";
                                $fallbackParams[] = $trimmedName;
                            }
                            $fallbackQuery .= " LIMIT 1";
                            $match = $db->queryOne($fallbackQuery, $fallbackParams);
                        }

                        $packagingNameCache[$normalizedSource] = $match ?: false;
                    }

                    $cached = $packagingNameCache[$normalizedSource];
                    return is_array($cached) && !empty($cached['id']) ? $cached : null;
                };

                $materialsConsumption = [
                    'raw' => [],
                    'packaging' => []
                ];
                $allSuppliers = [];

                $packagingSupplierId = null;
                $honeySupplierId = null;
                $honeyVariety = null;

                $packagingUsageLogsExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_usage_logs'"));

                foreach ($packagingItems as $pkg) {
                    $packagingMaterialId = isset($pkg['packaging_material_id']) ? (int)$pkg['packaging_material_id'] : 0;
                    $pkgKey = 'pack_' . ($packagingMaterialId ?: $pkg['id']);
                    $selectedSupplierId = $materialSuppliers[$pkgKey] ?? 0;
                    if ($selectedSupplierId <= 0) {
                        throw new Exception('يرجى اختيار المورد المناسب لأداة التعبئة قبل إنشاء التشغيلة.');
                    }

                    $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                    if (!$supplierInfo) {
                        throw new Exception('مورد غير صالح لأداة التعبئة: ' . ($pkg['packaging_name'] ?? 'غير معروف'));
                    }

                    $packagingName = trim((string)($pkg['packaging_name'] ?? ''));
                    $packagingUnit = 'قطعة';
                    $packagingProductId = null;
                    $packagingRow = null;

                    if ($packagingMaterialId > 0 && $packagingTableExists) {
                        $packagingRow = $db->queryOne(
                            "SELECT {$packagingSelectColumns} FROM packaging_materials WHERE id = ?",
                            [$packagingMaterialId]
                        );
                        if (!$packagingRow) {
                            $packagingMaterialId = 0;
                        }
                    }

                    if ($packagingMaterialId <= 0 && $packagingTableExists) {
                        $lookupName = $packagingName !== '' ? $packagingName : trim((string)($pkg['packaging_name'] ?? ''));
                        $resolvedRow = $resolvePackagingByName($lookupName);
                        if ($resolvedRow) {
                            $packagingRow = $resolvedRow;
                            $packagingMaterialId = (int)$resolvedRow['id'];
                        }
                    }

                    if ($packagingRow) {
                        if ($packagingName === '' && !empty($packagingRow['name'])) {
                            $packagingName = (string)$packagingRow['name'];
                        }
                        if (!empty($packagingRow['unit'])) {
                            $packagingUnit = $packagingRow['unit'];
                        }
                        if ($packagingProductColumnExists && !empty($packagingRow['product_id'])) {
                            $packagingProductId = (int)$packagingRow['product_id'];
                        }
                    }

                    if (!$packagingProductId) {
                        $packagingProductId = ensureProductionMaterialProductId(
                            $packagingName !== '' ? $packagingName : ('مادة تعبئة #' . ($packagingMaterialId ?: $pkg['id'])),
                            'packaging',
                            $packagingUnit
                        );
                    }

                    if ($packagingMaterialId > 0) {
                        $packagingIdsMap[$packagingMaterialId] = true;
                    }

                $materialsConsumption['packaging'][] = [
                        'material_id' => $packagingMaterialId > 0 ? $packagingMaterialId : null,
                        'quantity' => (float)($pkg['quantity_per_unit'] ?? 1.0) * $quantity,
                        'name' => $packagingName !== '' ? $packagingName : 'مادة تعبئة',
                        'unit' => $packagingUnit,
                        'product_id' => $packagingProductId,
                        'supplier_id' => $selectedSupplierId,
                        'template_item_id' => (int)($pkg['id'] ?? 0)
                    ];

                    $allSuppliers[] = [
                        'id' => $supplierInfo['id'],
                        'name' => $supplierInfo['name'],
                        'type' => $supplierInfo['type'],
                        'material' => $packagingName !== '' ? $packagingName : 'مادة تعبئة'
                    ];

                    if (!$packagingSupplierId) {
                        $packagingSupplierId = $supplierInfo['id'];
                    }
                }

                foreach ($rawMaterials as $raw) {
                    $rawId = isset($raw['id']) ? (int)$raw['id'] : 0;
                    $rawKey = 'raw_' . $rawId;
                    $selectedSupplierId = $materialSuppliers[$rawKey] ?? 0;
                    if ($selectedSupplierId <= 0) {
                        throw new Exception('يرجى اختيار المورد المناسب لكل مادة خام قبل إنشاء التشغيلة.');
                    }

                    $supplierInfo = $db->queryOne("SELECT id, name, type FROM suppliers WHERE id = ?", [$selectedSupplierId]);
                    if (!$supplierInfo) {
                        throw new Exception('مورد غير صالح للمادة الخام: ' . ($raw['material_name'] ?? 'غير معروف'));
                    }

                    $rawName = (string)($raw['material_name'] ?? 'مادة خام');
                    $rawUnit = $raw['unit'] ?? 'كجم';
                    $rawQuantityPerUnit = (float)($raw['quantity_per_unit'] ?? 0);
                    $rawProductId = ensureProductionMaterialProductId($rawName, 'raw_material', $rawUnit);

                    $detailEntry = null;
                    if ($rawId > 0 && isset($templateRawDetailsById[$rawId])) {
                        $detailEntry = $templateRawDetailsById[$rawId];
                    } else {
                        $normalizedRawName = $normalizeRawName($rawName);
                        if ($normalizedRawName !== '' && isset($templateRawDetailsByName[$normalizedRawName])) {
                            $detailEntry = $templateRawDetailsByName[$normalizedRawName];
                        }
                    }

                    $detailType = '';
                    if ($detailEntry && isset($detailEntry['type']) && is_string($detailEntry['type'])) {
                        $detailType = trim((string)$detailEntry['type']);
                    } elseif ($detailEntry && isset($detailEntry['material_type']) && is_string($detailEntry['material_type'])) {
                        $detailType = trim((string)$detailEntry['material_type']);
                    }

                    $materialType = $detailType !== '' ? $detailType : 'raw_general';
                    $materialType = function_exists('mb_strtolower') ? mb_strtolower($materialType, 'UTF-8') : strtolower($materialType);
                    if ($materialType === 'honey') {
                        $materialType = 'honey_filtered';
                    } elseif ($materialType === 'honey_general') {
                        $materialType = 'honey_filtered';
                    }

                    $isHoneyName = (mb_stripos($rawName, 'عسل') !== false) || (stripos($rawName, 'honey') !== false);
                    if (!in_array($materialType, ['honey_raw', 'honey_filtered', 'olive_oil', 'beeswax', 'derivatives', 'nuts'], true)) {
                        if ($isHoneyName) {
                            $hasRawKeyword = (mb_stripos($rawName, 'خام') !== false) || (stripos($rawName, 'raw') !== false);
                            $hasFilteredKeyword = (mb_stripos($rawName, 'مصفى') !== false) || (stripos($rawName, 'filtered') !== false);
                            if ($hasRawKeyword && !$hasFilteredKeyword) {
                                $materialType = 'honey_raw';
                            } elseif ($hasFilteredKeyword && !$hasRawKeyword) {
                                $materialType = 'honey_filtered';
                            } else {
                                $materialType = 'honey_filtered';
                            }
                        } else {
                            $materialType = 'raw_general';
                        }
                    }

                    $selectedHoneyVariety = trim((string)($materialHoneyVarieties[$rawKey] ?? ''));
                    if ($selectedHoneyVariety === '' && $detailEntry && !empty($detailEntry['honey_variety'])) {
                        $selectedHoneyVariety = trim((string)$detailEntry['honey_variety']);
                    }

                    if (in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                        if (!$honeySupplierId) {
                            $honeySupplierId = $supplierInfo['id'];
                        }
                        if ($selectedHoneyVariety !== '') {
                            $honeyVariety = $selectedHoneyVariety;
                        } elseif (is_array($detailEntry) && !empty($detailEntry['honey_variety'])) {
                            $honeyVariety = (string) $detailEntry['honey_variety'];
                        }
                    }

                    $materialsConsumption['raw'][] = [
                        'product_id' => $rawProductId,
                        'quantity' => $rawQuantityPerUnit * $quantity,
                        'material_name' => $rawName,
                        'supplier_id' => $selectedSupplierId,
                        'unit' => $rawUnit,
                        'material_type' => $materialType,
                        'display_name' => $rawName,
                        'honey_variety' => $selectedHoneyVariety !== ''
                            ? $selectedHoneyVariety
                            : (is_array($detailEntry) && isset($detailEntry['honey_variety']) ? $detailEntry['honey_variety'] : null),
                        'template_item_id' => $rawId
                    ];

                    $allSuppliers[] = [
                        'id' => $supplierInfo['id'],
                        'name' => $supplierInfo['name'],
                        'type' => $supplierInfo['type'],
                        'material' => $rawName,
                        'honey_variety' => $selectedHoneyVariety !== ''
                            ? $selectedHoneyVariety
                            : (is_array($detailEntry) && isset($detailEntry['honey_variety']) ? $detailEntry['honey_variety'] : null)
                    ];
                }

                if (!$packagingSupplierId && !empty($materialSuppliers)) {
                    foreach ($materialSuppliers as $key => $value) {
                        if (strpos($key, 'pack_') === 0 && $value > 0) {
                            $packagingSupplierId = $value;
                            break;
                        }
                    }
                }

                $packagingIds = array_map('intval', array_keys($packagingIdsMap));
                
                // إنشاء المنتج إذا لم يكن موجودًا
                $productId = $template['product_id'] ?? 0;
                if ($productId <= 0) {
                    // البحث عن منتج بنفس الاسم
                    $existingProduct = $db->queryOne("SELECT id FROM products WHERE name = ? LIMIT 1", [$template['product_name']]);
                    if ($existingProduct) {
                        $productId = $existingProduct['id'];
                    } else {
                        $insertProductName = trim((string)$template['product_name']);
                        if ($insertProductName === '') {
                            $insertProductName = 'منتج رقم ' . ($template['id'] ?? '?');
                        }
                        // إنشاء منتج جديد
                        $result = $db->execute(
                            "INSERT INTO products (name, category, status, unit) VALUES (?, 'finished', 'active', 'قطعة')",
                            [$insertProductName]
                        );
                        $productId = $result['insert_id'];
                    }
                }
                // التحقق من توافر نوع العسل لدى المورد المحدد
                $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
                foreach ($materialsConsumption['raw'] as $rawItem) {
                    $materialType = $rawItem['material_type'] ?? '';
                    if (!in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                        continue;
                    }
                    
                    $supplierForHoney = $rawItem['supplier_id'] ?? null;
                    $requiredHoneyQuantity = (float)($rawItem['quantity'] ?? 0);
                    if (!$supplierForHoney || $requiredHoneyQuantity <= 0) {
                        continue;
                    }
                    
                    if ($honeyStockTableCheck) {
                        $stockColumn = $materialType === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                        $params = [$supplierForHoney];
                        $honeySql = "SELECT {$stockColumn} AS available_quantity, honey_variety 
                                     FROM honey_stock 
                                     WHERE supplier_id = ?";
                        
                        if (!empty($rawItem['honey_variety'])) {
                            $honeySql .= " AND honey_variety = ?";
                            $params[] = $rawItem['honey_variety'];
                        }
                        
                        $honeySql .= " ORDER BY {$stockColumn} DESC LIMIT 1";
                        $supplierHoney = $db->queryOne($honeySql, $params);
                        
                        if (!$supplierHoney) {
                            $varietyLabel = $rawItem['honey_variety'] ?: ($rawItem['material_name'] ?: 'العسل المطلوب');
                            throw new Exception('المورد المحدد لا تتوفر لديه كمية كافية من نوع العسل: ' . $varietyLabel);
                        }
                        
                        $availableHoney = (float)($supplierHoney['available_quantity'] ?? 0);
                        if ($availableHoney < $requiredHoneyQuantity) {
                            $varietyLabel = $supplierHoney['honey_variety'] ?? $rawItem['honey_variety'] ?? ($rawItem['material_name'] ?: 'العسل المطلوب');
                            throw new Exception(sprintf(
                                'الكمية المتاحة لدى المورد %s غير كافية. مطلوب %.2f كجم، متوفر %.2f كجم.',
                                $varietyLabel,
                                $requiredHoneyQuantity,
                                $availableHoney
                            ));
                        }
                    }
                }
                
                // تحديد معرف المستخدم
                $selectedUserId = $currentUser['role'] === 'production' ? $currentUser['id'] : intval($_POST['user_id'] ?? $currentUser['id']);
                
                // 3. جمع عمال الإنتاج الحاضرين داخل الموقع
                $workersList = [];
                $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                if (!empty($attendanceTableCheck)) {
                    // استرجاع عمال الإنتاج الذين سجلوا الحضور اليوم
                    $presentWorkers = $db->query(
                        "SELECT DISTINCT user_id 
                         FROM attendance_records 
                         WHERE date = ? 
                         AND check_in_time IS NOT NULL 
                         AND user_id IN (SELECT id FROM users WHERE role = 'production' AND status = 'active')
                         ORDER BY check_in_time DESC",
                        [$productionDate]
                    );
                    
                    foreach ($presentWorkers as $worker) {
                        $workersList[] = intval($worker['user_id']);
                    }
                }
                
                // إذا لم يوجد عمال حاضرين، استخدم المستخدم الحالي فقط
                if (empty($workersList)) {
                    $workersList = [$selectedUserId];
                }
                
                $extraSuppliersDetails = [];
                if (!empty($extraSupplierIds)) {
                    $supplierPlaceholders = implode(',', array_fill(0, count($extraSupplierIds), '?'));
                    $suppliersResult = $db->query(
                        "SELECT id, name, type FROM suppliers WHERE id IN ($supplierPlaceholders)",
                        $extraSupplierIds
                    );
                    $suppliersById = [];
                    foreach ($suppliersResult as $supplierRow) {
                        $suppliersById[(int)($supplierRow['id'] ?? 0)] = $supplierRow;
                    }
                    foreach ($extraSupplierIds as $selectedSupplierId) {
                        if (isset($suppliersById[$selectedSupplierId])) {
                            $extraSuppliersDetails[] = $suppliersById[$selectedSupplierId];
                        }
                    }
                }

                if (!empty($extraSuppliersDetails)) {
                    $existingSupplierIds = [];
                    foreach ($allSuppliers as $supplierEntry) {
                        if (isset($supplierEntry['id']) && $supplierEntry['id']) {
                            $existingSupplierIds[(int)$supplierEntry['id']] = true;
                        }
                    }

                    foreach ($extraSuppliersDetails as $supplierRow) {
                        $supplierIdValue = (int)($supplierRow['id'] ?? 0);
                        if ($supplierIdValue > 0 && isset($existingSupplierIds[$supplierIdValue])) {
                            continue;
                        }
                        if ($supplierIdValue > 0) {
                            $existingSupplierIds[$supplierIdValue] = true;
                        }
                        $allSuppliers[] = [
                            'id' => $supplierIdValue > 0 ? $supplierIdValue : null,
                            'name' => $supplierRow['name'] ?? 'مورد إضافي',
                            'type' => $supplierRow['type'] ?? null,
                            'material' => 'مورد إضافي (اختياري)',
                            'context' => 'manual_extra_supplier'
                        ];
                    }
                }

                // 4. الملاحظات: توليد ملاحظات تلقائية تذكر الموردين
                $batchNotes = '';
                $notesParts = ['تم إنشاؤه من قالب: ' . $template['product_name']];
                
                // إضافة جميع الموردين إلى الملاحظات
                if (!empty($allSuppliers)) {
                    $supplierNames = [];
                    foreach ($allSuppliers as $supplier) {
                        $supplierNames[] = $supplier['name'] . ' (' . $supplier['material'] . ')';
                    }
                    $notesParts[] = 'الموردون: ' . implode(', ', $supplierNames);
                }

                if (!empty($extraSuppliersDetails)) {
                    $manualSupplierLabels = [];
                    foreach ($extraSuppliersDetails as $supplierRow) {
                        $label = $supplierRow['name'] ?? '';
                        $typeLabel = $supplierRow['type'] ?? '';
                        if ($label === '') {
                            $label = 'مورد #' . ($supplierRow['id'] ?? '');
                        }
                        if ($typeLabel !== '') {
                            $label .= ' (' . $typeLabel . ')';
                        }
                        $manualSupplierLabels[] = $label;
                    }
                    if (!empty($manualSupplierLabels)) {
                        $notesParts[] = 'موردون إضافيون: ' . implode(', ', $manualSupplierLabels);
                    }
                }

                $batchNotes = implode(' | ', $notesParts);
                
                // إنشاء سجل إنتاج واحد للتشغيلة
                $columns = ['product_id', 'quantity'];
                $values = [$productId, $quantity]; // الكمية النهائية
                $placeholders = ['?', '?'];
                
                $columns[] = $dateColumn;
                $values[] = $productionDate;
                $placeholders[] = '?';
                
                if ($userIdColumn) {
                    $columns[] = $userIdColumn;
                    $values[] = $selectedUserId;
                    $placeholders[] = '?';
                }
                
                $columns[] = 'status';
                $values[] = 'completed';
                $placeholders[] = '?';
                
                $sql = "INSERT INTO production (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $result = $db->execute($sql, $values);
                $productionId = $result['insert_id'];
                
                // إنشاء رقم تشغيلة واحد لجميع المنتجات
                $batchResult = createBatchNumber(
                    $productId,
                    $productionId,
                    $productionDate,
                    $honeySupplierId, // مورد العسل (إن توفر)
                    $packagingIds,
                    $packagingSupplierId,
                    $workersList, // العمال الحاضرون (قائمة)
                    $quantity, // الكمية النهائية
                    null, // expiry_date
                    $batchNotes, // الملاحظات (إن وُجدت)
                    $currentUser['id'],
                    $allSuppliers, // جميع الموردين مع المواد
                    $honeyVariety ?? null, // نوع العسل المستخدم
                    $templateId,
                    $materialsConsumption['raw'],
                    $materialsConsumption['packaging']
                );
                
                if (!$batchResult['success']) {
                    throw new Exception('فشل في إنشاء رقم التشغيلة: ' . $batchResult['message']);
                }
                
                $batchNumber = $batchResult['batch_number'];

                storeProductionMaterialsUsage($productionId, $materialsConsumption['raw'], $materialsConsumption['packaging']);

                if (empty($batchResult['stock_deducted'])) {
                    try {
                        foreach ($materialsConsumption['raw'] as $rawItem) {
                        $deductQuantity = (float)($rawItem['quantity'] ?? 0);
                        if ($deductQuantity <= 0) {
                            continue;
                        }

                        $materialType = $rawItem['material_type'] ?? '';
                        $supplierForDeduction = $rawItem['supplier_id'] ?? null;
                        $materialName = $rawItem['material_name'] ?? '';

                        switch ($materialType) {
                            case 'honey_raw':
                            case 'honey_filtered':
                                if ($supplierForDeduction) {
                                    $stockColumn = $materialType === 'honey_raw' ? 'raw_honey_quantity' : 'filtered_honey_quantity';
                                    $db->execute(
                                        "UPDATE honey_stock 
                                         SET {$stockColumn} = GREATEST({$stockColumn} - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'olive_oil':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE olive_oil_stock 
                                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'beeswax':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE beeswax_stock 
                                         SET weight = GREATEST(weight - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'derivatives':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE derivatives_stock 
                                         SET weight = GREATEST(weight - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'nuts':
                                if ($supplierForDeduction) {
                                    $db->execute(
                                        "UPDATE nuts_stock 
                                         SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                         WHERE supplier_id = ?",
                                        [$deductQuantity, $supplierForDeduction]
                                    );
                                }
                                break;
                            case 'legacy':
                                // لا يوجد تعريف واضح للمورد في القوالب القديمة؛ يتم تجاوز الخصم تلقائيًا
                                break;
                            default:
                                if ($materialName !== '') {
                                    $matchedProduct = $db->queryOne(
                                        "SELECT id FROM products WHERE name = ? LIMIT 1",
                                        [$materialName]
                                    );
                                    if ($matchedProduct) {
                                        $db->execute(
                                            "UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE id = ?",
                                            [$deductQuantity, $matchedProduct['id']]
                                        );
                                    }
                                }
                                break;
                        }
                    }

                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                        $packMaterialId = isset($packItem['material_id']) ? (int)$packItem['material_id'] : 0;
                        $packQuantity = (float)($packItem['quantity'] ?? 0);
                        if ($packMaterialId <= 0 && $packQuantity > 0 && $packagingTableExists) {
                            $lookupName = trim((string)($packItem['name'] ?? ''));
                            $resolvedRow = $resolvePackagingByName($lookupName);
                            if ($resolvedRow) {
                                $packMaterialId = (int)$resolvedRow['id'];
                                $packItem['material_id'] = $packMaterialId;
                            }
                        }
                        if ($packMaterialId > 0 && $packQuantity > 0) {
                            $quantityBefore = null;
                            $materialNameForLog = $packItem['name'] ?? null;
                            $materialUnitForLog = $packItem['unit'] ?? 'وحدة';

                            if ($packagingUsageLogsExists) {
                                try {
                                    $packagingRowForLog = $db->queryOne(
                                        "SELECT name, unit, quantity FROM packaging_materials WHERE id = ?",
                                        [$packMaterialId]
                                    );
                                    if ($packagingRowForLog) {
                                        $quantityBefore = (float)($packagingRowForLog['quantity'] ?? 0);
                                        if (!empty($packagingRowForLog['name'])) {
                                            $materialNameForLog = $packagingRowForLog['name'];
                                        }
                                        if (!empty($packagingRowForLog['unit'])) {
                                            $materialUnitForLog = $packagingRowForLog['unit'];
                                        }
                                    }
                                } catch (Exception $packagingLogFetchError) {
                                    error_log('Production packaging usage fetch warning: ' . $packagingLogFetchError->getMessage());
                                }
                            }

                            $db->execute(
                                "UPDATE packaging_materials 
                                 SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                 WHERE id = ?",
                                [$packQuantity, $packMaterialId]
                            );

                            if ($packagingUsageLogsExists && $quantityBefore !== null) {
                                $quantityAfter = max($quantityBefore - $packQuantity, 0);
                                $quantityUsed = $quantityBefore - $quantityAfter;

                                if ($quantityUsed > 0) {
                                    try {
                                        $db->execute(
                                            "INSERT INTO packaging_usage_logs 
                                             (material_id, material_name, material_code, source_table, quantity_before, quantity_used, quantity_after, unit, used_by) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                            [
                                                $packMaterialId,
                                                $materialNameForLog,
                                                null,
                                                'packaging_materials',
                                                $quantityBefore,
                                                $quantityUsed,
                                                $quantityAfter,
                                                $materialUnitForLog ?: 'وحدة',
                                                $currentUser['id'] ?? null
                                            ]
                                        );
                                    } catch (Exception $packagingUsageInsertError) {
                                        error_log('Production packaging usage log insert failed: ' . $packagingUsageInsertError->getMessage());
                                    }
                                }
                            }
                        }
                        }
                        unset($packItem);
                    } catch (Exception $stockWarning) {
                        error_log('Production stock deduction warning: ' . $stockWarning->getMessage());
                    }
                }
                
                // إنشاء أرقام باركود بعدد الكمية المنتجة
                $batchNumbersToPrint = [];
                for ($i = 0; $i < $quantity; $i++) {
                    $batchNumbersToPrint[] = $batchNumber;
                }
                
                $db->commit();
                
                logAudit($currentUser['id'], 'create_from_template', 'production', $productionId, null, [
                    'template_id' => $templateId,
                    'quantity' => $quantity,
                    'batch_number' => $batchNumber,
                    'honey_supplier_id' => $honeySupplierId,
                    'packaging_supplier_id' => $packagingSupplierId
                ]);

                // إعداد بيانات التشغيله لإعادة استخدامها في الإشعارات
                $workerNames = [];
                if (!empty($workersList)) {
                    $workerIdsForQuery = array_values(array_filter(array_map('intval', $workersList), static function ($value) {
                        return $value > 0;
                    }));
                    if (!empty($workerIdsForQuery)) {
                        $workerPlaceholders = implode(',', array_fill(0, count($workerIdsForQuery), '?'));
                        try {
                            $workerRows = $db->query(
                                "SELECT id, COALESCE(NULLIF(full_name, ''), username) AS display_name 
                                 FROM users 
                                 WHERE id IN ($workerPlaceholders)",
                                $workerIdsForQuery
                            );
                            foreach ($workerRows as $workerRow) {
                                $displayName = trim((string)($workerRow['display_name'] ?? ''));
                                if ($displayName === '') {
                                    $displayName = 'مستخدم #' . ($workerRow['id'] ?? '?');
                                }
                                $workerNames[] = $displayName;
                            }
                        } catch (Throwable $workerFetchError) {
                            error_log('Production workers fetch failed: ' . $workerFetchError->getMessage());
                        }
                    }
                }
                $workerNames = array_values(array_unique($workerNames));

                $honeySupplierName = null;
                if (!empty($honeySupplierId)) {
                    try {
                        $honeySupplierRow = $db->queryOne(
                            "SELECT name FROM suppliers WHERE id = ? LIMIT 1",
                            [$honeySupplierId]
                        );
                        if (!empty($honeySupplierRow['name'])) {
                            $honeySupplierName = $honeySupplierRow['name'];
                        }
                    } catch (Throwable $supplierError) {
                        error_log('Production honey supplier fetch failed: ' . $supplierError->getMessage());
                    }
                }

                $packagingSupplierName = null;
                if (!empty($packagingSupplierId)) {
                    try {
                        $packagingSupplierRow = $db->queryOne(
                            "SELECT name FROM suppliers WHERE id = ? LIMIT 1",
                            [$packagingSupplierId]
                        );
                        if (!empty($packagingSupplierRow['name'])) {
                            $packagingSupplierName = $packagingSupplierRow['name'];
                        }
                    } catch (Throwable $supplierError) {
                        error_log('Production packaging supplier fetch failed: ' . $supplierError->getMessage());
                    }
                }

                $extraSuppliersNames = [];
                if (!empty($extraSuppliersDetails) && is_array($extraSuppliersDetails)) {
                    foreach ($extraSuppliersDetails as $supplierRow) {
                        $label = trim((string)($supplierRow['name'] ?? ''));
                        if ($label === '' && !empty($supplierRow['type'])) {
                            $label = 'مورد (' . $supplierRow['type'] . ')';
                        }
                        if ($label !== '') {
                            $extraSuppliersNames[] = $label;
                        }
                    }
                }
                $extraSuppliersNames = array_values(array_unique($extraSuppliersNames));

                $rawMaterialsSummary = [];
                if (!empty($materialsConsumption['raw']) && is_array($materialsConsumption['raw'])) {
                    foreach ($materialsConsumption['raw'] as $rawItem) {
                        $rawMaterialsSummary[] = [
                            'name' => trim((string)($rawItem['material_name'] ?? $rawItem['honey_variety'] ?? '')),
                            'quantity' => isset($rawItem['quantity']) ? (float)$rawItem['quantity'] : null,
                            'unit' => $rawItem['unit'] ?? ($rawItem['material_unit'] ?? null),
                            'supplier_id' => isset($rawItem['supplier_id']) ? (int)$rawItem['supplier_id'] : null,
                        ];
                    }
                }
                $rawMaterialsSummary = array_values($rawMaterialsSummary);

                $packagingMaterialsSummary = [];
                if (!empty($materialsConsumption['packaging']) && is_array($materialsConsumption['packaging'])) {
                    foreach ($materialsConsumption['packaging'] as $packItem) {
                        $packagingMaterialsSummary[] = [
                            'name' => trim((string)($packItem['name'] ?? '')),
                            'quantity' => isset($packItem['quantity']) ? (float)$packItem['quantity'] : null,
                            'unit' => $packItem['unit'] ?? null,
                            'material_id' => isset($packItem['material_id']) ? (int)$packItem['material_id'] : null,
                        ];
                    }
                }
                $packagingMaterialsSummary = array_values($packagingMaterialsSummary);

                $contextToken = null;
                if (function_exists('random_bytes')) {
                    try {
                        $contextToken = bin2hex(random_bytes(16));
                    } catch (Throwable $tokenError) {
                        $contextToken = null;
                    }
                }
                if (!$contextToken && function_exists('openssl_random_pseudo_bytes')) {
                    $opensslBytes = @openssl_random_pseudo_bytes(16);
                    if ($opensslBytes !== false) {
                        $contextToken = bin2hex($opensslBytes);
                    }
                }
                if (!$contextToken) {
                    $contextToken = sha1(uniqid((string) mt_rand(), true));
                }

                $_SESSION['created_batch_context_token'] = $contextToken;
                $_SESSION['created_batch_metadata'] = [
                    'batch_number' => $batchNumber,
                    'batch_id' => $batchResult['batch_id'] ?? null,
                    'production_id' => $productionId,
                    'product_id' => $productId,
                    'product_name' => $batchResult['product_name'] ?? ($template['product_name'] ?? ''),
                    'production_date' => $batchResult['production_date'] ?? $productionDate,
                    'quantity' => $quantity,
                    'unit' => 'قطعة',
                    'quantity_unit_label' => $template['unit'] ?? null,
                    'created_by' => $currentUser['full_name'] ?? ($currentUser['username'] ?? ''),
                    'created_by_id' => $currentUser['id'] ?? null,
                    'workers' => $workerNames,
                    'workers_ids' => array_values(array_map('intval', $workersList)),
                    'honey_supplier_name' => $honeySupplierName,
                    'honey_supplier_id' => !empty($honeySupplierId) ? (int)$honeySupplierId : null,
                    'packaging_supplier_name' => $packagingSupplierName,
                    'packaging_supplier_id' => !empty($packagingSupplierId) ? (int)$packagingSupplierId : null,
                    'extra_suppliers' => $extraSuppliersNames,
                    'extra_suppliers_ids' => isset($extraSupplierIds) && is_array($extraSupplierIds)
                        ? array_values(array_map('intval', $extraSupplierIds))
                        : [],
                    'notes' => $batchNotes,
                    'raw_materials' => $rawMaterialsSummary,
                    'packaging_materials' => $packagingMaterialsSummary,
                    'template_id' => $templateId,
                    'timestamp' => date('c'),
                    'context_token' => $contextToken,
                ];

                // حفظ أرقام التشغيلة في الجلسة لعرضها في نافذة الطباعة
                $_SESSION['created_batch_numbers'] = $batchNumbersToPrint; // أرقام باركود بعدد الكمية
                $_SESSION['created_batch_product_name'] = $template['product_name'];
                $_SESSION['created_batch_quantity'] = $quantity;
                
                // منع التكرار باستخدام إعادة التوجيه
                $successMessage = 'تم إنشاء تشغيلة إنتاج بنجاح! رقم التشغيلة: ' . $batchNumber . ' (الكمية: ' . $quantity . ' قطعة)';
                $redirectParams = ['page' => 'production', 'show_barcode_modal' => '1'];
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'حدث خطأ في إنشاء الإنتاج: ' . $e->getMessage();
                error_log("Create from template error: " . $e->getMessage());
            }
        }
    }
    // منع إنشاء قوالب المنتجات من خارج صفحة مخزن الخامات
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
// عدد السجلات في كل صفحة
$perPage = 5; // خمسة عناصر في كل صفحة
$offset = ($pageNum - 1) * $perPage;

// معايير البحث والتصفية
$search = $_GET['search'] ?? '';
$productId = $_GET['product_id'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// بناء شروط الاستعلام
$whereConditions = ['1=1'];
$params = [];

if ($search) {
    $searchParam = '%' . $search . '%';
    $searchConditions = [
        'p.id LIKE ?',
        'pr.name LIKE ?'
    ];
    $searchParams = [
        $searchParam,
        $searchParam
    ];

    if ($hasBatchNumbersTable && $hasFinishedProductsTable) {
        $searchConditions[] = 'fp_template.template_product_name LIKE ?';
        $searchParams[] = $searchParam;
    }

    if ($userIdColumn) {
        $searchConditions[] = 'u.full_name LIKE ?';
        $searchParams[] = $searchParam;
    }

    $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
    foreach ($searchParams as $paramValue) {
        $params[] = $paramValue;
    }
}

if ($productId) {
    $whereConditions[] = "p.product_id = ?";
    $params[] = intval($productId);
}

if ($status) {
    $whereConditions[] = "p.status = ?";
    $params[] = $status;
}

if ($dateFrom) {
    $whereConditions[] = "DATE(p.$dateColumn) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $whereConditions[] = "DATE(p.$dateColumn) <= ?";
    $params[] = $dateTo;
}

$whereClause = implode(' AND ', $whereConditions);

$productNameSelect = 'pr.name AS product_name';
$templateProductJoin = '';
if ($hasBatchNumbersTable && $hasFinishedProductsTable) {
    $templateProductJoin = "
                 LEFT JOIN (
                     SELECT 
                         bn_inner.production_id, 
                         MAX(fp_inner.product_name) AS template_product_name
                     FROM batch_numbers bn_inner
                     INNER JOIN finished_products fp_inner ON fp_inner.batch_number = bn_inner.batch_number
                     GROUP BY bn_inner.production_id
                 ) fp_template ON fp_template.production_id = p.id";
    $productNameSelect = 'COALESCE(fp_template.template_product_name, pr.name) AS product_name';
}

// حساب إجمالي السجلات
if ($userIdColumn) {
    $countSql = "SELECT COUNT(*) as total 
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id" . $templateProductJoin . "
                 LEFT JOIN users u ON p.{$userIdColumn} = u.id
                 WHERE $whereClause";
} else {
    $countSql = "SELECT COUNT(*) as total 
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id" . $templateProductJoin . "
                 WHERE $whereClause";
}

$totalResult = $db->queryOne($countSql, $params);
$totalProduction = $totalResult['total'] ?? 0;
$totalPages = ceil($totalProduction / $perPage);

// حساب إجمالي الكمية المنتجة
$totalQuantitySql = str_replace('COUNT(*) as total', 'COALESCE(SUM(p.quantity), 0) as total', $countSql);
$totalQuantityResult = $db->queryOne($totalQuantitySql, $params);
$totalQuantity = floatval($totalQuantityResult['total'] ?? 0);

// جلب بيانات التشغيلات (أرقام الدُفعات)
$batchJoinSelect = $hasBatchNumbersTable ? ', bn.batch_number as batch_number' : ', NULL as batch_number';
$batchJoinClause = '';

if ($hasBatchNumbersTable) {
    $batchJoinClause = "
            LEFT JOIN (
                SELECT b1.production_id, b1.batch_number
                FROM batch_numbers b1
                WHERE b1.production_id IS NOT NULL
                  AND b1.id = (
                      SELECT MAX(b2.id)
                      FROM batch_numbers b2
                      WHERE b2.production_id = b1.production_id
                  )
            ) bn ON bn.production_id = p.id";
}

if ($userIdColumn) {
    $sql = "SELECT p.*, 
                   {$productNameSelect}, 
                   pr.category as product_category,
                   u.full_name as worker_name,
                   u.username as worker_username
                   $batchJoinSelect
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id" . $templateProductJoin . "
            LEFT JOIN users u ON p.{$userIdColumn} = u.id
            $batchJoinClause
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
} else {
    $sql = "SELECT p.*, 
                   {$productNameSelect}, 
                   pr.category as product_category,
                   'غير محدد' as worker_name,
                   'غير محدد' as worker_username
                   $batchJoinSelect
            FROM production p
            LEFT JOIN products pr ON p.product_id = pr.id" . $templateProductJoin . "
            $batchJoinClause
            WHERE $whereClause
            ORDER BY p.$dateColumn DESC, p.created_at DESC
            LIMIT ? OFFSET ?";
}

$params[] = $perPage;
$params[] = $offset;

$productions = $db->query($sql, $params);

// جلب المنتجات النشطة لعرضها في القوائم
$products = $db->query("SELECT id, name, category FROM products WHERE status = 'active' ORDER BY name");
$workers = $db->query("SELECT id, username, full_name FROM users WHERE role = 'production' AND status = 'active' ORDER BY username");

// جلب قائمة الموردين
$suppliers = [];
$suppliersTableCheck = $db->queryOne("SHOW TABLES LIKE 'suppliers'");
if (!empty($suppliersTableCheck)) {
    $suppliers = $db->query("SELECT id, name, type FROM suppliers WHERE status = 'active' ORDER BY name");
}

// إنشاء جداول القوالب إذا لم تكن موجودة
try {
    $templatesTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
    if (empty($templatesTableCheck)) {
        // إنشاء جدول product_templates
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `product_name` varchar(255) NOT NULL COMMENT 'اسم المنتج',
              `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Honey quantity in kilograms',
              `status` enum('active','inactive') DEFAULT 'active',
              `created_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `created_by` (`created_by`),
              KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // إضافة عمود honey_quantity إذا لم يكن موجودًا مسبقًا
        $honeyColumnCheck = $db->queryOne("SHOW COLUMNS FROM product_templates LIKE 'honey_quantity'");
        if (empty($honeyColumnCheck)) {
            try {
                $db->execute("
                    ALTER TABLE `product_templates` 
                    ADD COLUMN `honey_quantity` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'Honey quantity in kilograms' 
                    AFTER `product_name`
                ");
                error_log("Added honey_quantity column to product_templates table");
            } catch (Exception $e) {
                error_log("Error adding honey_quantity column: " . $e->getMessage());
            }
        }
    }
    
    // إ?شاء جد^" product_template_packaging
    $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
    if (empty($packagingTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_template_packaging` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `packaging_material_id` int(11) DEFAULT NULL,
              `packaging_name` varchar(255) NOT NULL,
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`),
              KEY `packaging_material_id` (`packaging_material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    // إنشاء جدول product_template_raw_materials
    $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
    if (empty($rawMaterialsTableCheck)) {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_id` int(11) NOT NULL,
              `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة الخام',
              `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية المادة الخام بالكيلوغرام',
              `unit` varchar(50) DEFAULT 'كجم',
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `template_id` (`template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        
        $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_packaging'");
        if (empty($packagingTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_packaging` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `packaging_material_id` int(11) DEFAULT NULL,
                  `packaging_name` varchar(255) NOT NULL,
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 1.000,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`),
                  KEY `packaging_material_id` (`packaging_material_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $rawMaterialsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_template_raw_materials'");
        if (empty($rawMaterialsTableCheck)) {
            $db->execute("
                CREATE TABLE IF NOT EXISTS `product_template_raw_materials` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `template_id` int(11) NOT NULL,
                  `material_name` varchar(255) NOT NULL COMMENT 'اسم المادة الخام',
                  `quantity_per_unit` decimal(10,3) NOT NULL DEFAULT 0.000 COMMENT 'كمية المادة الخام بالكيلوغرام',
                  `unit` varchar(50) DEFAULT 'كجم',
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `template_id` (`template_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
} catch (Exception $e) {
    error_log("Product templates tables creation error: " . $e->getMessage());
}

// جلب قوالب المنتجات عبر جميع الأقسام
$templates = [];

// 0. القوالب الموحدة الحديثة (متعددة المواد)
$unifiedTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'unified_product_templates'");
if (!empty($unifiedTemplatesCheck)) {
    // التحقق من وجود جدول المواد الخام وإضافة عمود honey_variety عند الحاجة
    $templateRawMaterialsCheck = $db->queryOne("SHOW TABLES LIKE 'template_raw_materials'");
    $hasHoneyVariety = false;
    
    if (!empty($templateRawMaterialsCheck)) {
        $honeyVarietyCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'honey_variety'");
        $hasHoneyVariety = !empty($honeyVarietyCheck);
        
        // إضافة العمود إذا لم يكن موجودًا
        if (!$hasHoneyVariety) {
            try {
                $db->execute("
                    ALTER TABLE `template_raw_materials` 
                    ADD COLUMN `honey_variety` VARCHAR(50) DEFAULT NULL COMMENT 'Honey variety used' 
                    AFTER `supplier_id`
                ");
                $hasHoneyVariety = true;
            } catch (Exception $e) {
                error_log("Error adding honey_variety column: " . $e->getMessage());
            }
        }
    }
    
    $unifiedHasMainSupplierColumn = productionColumnExists('unified_product_templates', 'main_supplier_id');
    $unifiedSql = "
        SELECT upt.*, 
               'unified' AS template_type,
               u.full_name AS creator_name";
    if ($unifiedHasMainSupplierColumn) {
        $unifiedSql .= ",
               ms.name AS main_supplier_name,
               ms.phone AS main_supplier_phone";
    }
    $unifiedSql .= "
        FROM unified_product_templates upt
        LEFT JOIN users u ON upt.created_by = u.id";
    if ($unifiedHasMainSupplierColumn) {
        $unifiedSql .= "
        LEFT JOIN suppliers ms ON upt.main_supplier_id = ms.id";
    }
    $unifiedSql .= "
        WHERE upt.status = 'active'
        ORDER BY upt.created_at DESC";

    $unifiedTemplates = $db->query($unifiedSql);
    
    $templatePackagingCheck = $db->queryOne("SHOW TABLES LIKE 'template_packaging'");

    foreach ($unifiedTemplates as &$template) {
        // جلب المواد الخام المرتبطة بالقالب
        if (!empty($templateRawMaterialsCheck)) {
            $selectColumns = "material_type, material_name, quantity, unit";
            if ($hasHoneyVariety) {
                $selectColumns = "material_type, material_name, honey_variety, quantity, unit";
            }
            
            $rawMaterials = $db->query(
                "SELECT {$selectColumns}
                 FROM template_raw_materials 
                 WHERE template_id = ?",
                [$template['id']]
            );
        } else {
            $rawMaterials = [];
        }
        
        $template['material_details'] = [];
        foreach ($rawMaterials as $material) {
            // ترجمة اسم المادة إلى العربية إن كانت قيمة معرفية
            $materialNameArabic = trim((string)($material['material_name'] ?? ''));
            
            // قاموس للترجمات الشائعة
            $materialTranslations = [
                ':honey_filtered' => 'عسل مصفى',
                ':honey_raw' => 'عسل خام',
                'honey_filtered' => 'عسل مصفى',
                'honey_raw' => 'عسل خام',
                ':olive_oil' => 'زيت زيتون',
                'olive_oil' => 'زيت زيتون',
                ':beeswax' => 'شمع عسل',
                'beeswax' => 'شمع عسل',
                ':derivatives' => 'مشتقات',
                'derivatives' => 'مشتقات',
                ':nuts' => 'مكسرات',
                'nuts' => 'مكسرات',
                ':other' => 'مواد أخرى',
                'other' => 'مواد أخرى'
            ];
            
            // تطبيق الترجمة إذا كانت متاحة
            if (isset($materialTranslations[$materialNameArabic])) {
                $materialNameArabic = $materialTranslations[$materialNameArabic];
            }
            
            $materialDisplay = $materialNameArabic;
            
            // إضافة نوع العسل إن وُجد (فقط إذا توافر العمود)
            if ($hasHoneyVariety 
                && ($material['material_type'] === 'honey_raw' || $material['material_type'] === 'honey_filtered') 
                && !empty($material['honey_variety'])) {
                $materialDisplay .= ' (' . $material['honey_variety'] . ')';
            }
            
            $quantityValue = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
            $unitValue = trim((string)($material['unit'] ?? ''));
            if ($unitValue === '') {
                $unitValue = 'وحدة';
            }

            $materialTypeValue = isset($material['material_type']) ? (string)$material['material_type'] : 'other';

            $template['material_details'][] = [
                'type' => $materialDisplay !== '' ? $materialDisplay : 'مادة خام',
                'quantity' => $quantityValue,
                'unit' => $unitValue,
                'honey_variety' => $material['honey_variety'] ?? null,
                'material_type' => $materialTypeValue
            ];
        }

        // جلب أدوات التعبئة المرتبطة بالقالب
        $packagingDetails = [];
        if (!empty($templatePackagingCheck)) {
            try {
                $hasTemplatePackagingNameColumn = productionColumnExists('template_packaging', 'packaging_name');
                $packagingNameSelect = $hasTemplatePackagingNameColumn
                    ? 'tp.packaging_name'
                    : "COALESCE(pm.name, CONCAT('أداة تعبئة #', tp.packaging_material_id))";

                $packagingSql = "
                    SELECT tp.packaging_material_id, tp.quantity_per_unit,
                           {$packagingNameSelect} AS packaging_name,
                           pm.unit AS packaging_unit
                    FROM template_packaging tp
                    LEFT JOIN packaging_materials pm ON tp.packaging_material_id = pm.id
                    WHERE tp.template_id = ?
                ";

                try {
                    $packagingItems = $db->query($packagingSql, [$template['id']]);

                    foreach ($packagingItems as $item) {
                        $quantity = isset($item['quantity_per_unit']) ? (float)$item['quantity_per_unit'] : 0.0;
                        if ($quantity <= 0) {
                            continue;
                        }

                        $packagingDetails[] = [
                            'name' => $item['packaging_name'] ?? 'مادة تعبئة',
                            'quantity_per_unit' => $quantity,
                            'unit' => $item['packaging_unit'] ?? 'وحدة'
                        ];
                    }
                } catch (Exception $innerError) {
                    // في حال فشل الاستعلام الأساسي بسبب عمود مفقود، كرر بدون الأعمدة الاختيارية
                    $fallbackItems = $db->query(
                        "SELECT tp.packaging_material_id, tp.quantity_per_unit,
                                COALESCE(pm.name, CONCAT('أداة تعبئة #', tp.packaging_material_id)) AS packaging_name,
                                pm.unit AS packaging_unit
                         FROM template_packaging tp
                         LEFT JOIN packaging_materials pm ON tp.packaging_material_id = pm.id
                         WHERE tp.template_id = ?",
                        [$template['id']]
                    );

                    foreach ($fallbackItems as $item) {
                        $quantity = isset($item['quantity_per_unit']) ? (float)$item['quantity_per_unit'] : 0.0;
                        if ($quantity <= 0) {
                            continue;
                        }

                        $packagingDetails[] = [
                            'name' => $item['packaging_name'] ?? 'مادة تعبئة',
                            'quantity_per_unit' => $quantity,
                            'unit' => $item['packaging_unit'] ?? 'وحدة'
                        ];
                    }
                }
            } catch (Exception $e) {
                error_log("Fetching template packaging failed for template {$template['id']}: " . $e->getMessage());
            }
        }

        $template['packaging_details'] = $packagingDetails;
        $template['packaging_count'] = count($packagingDetails);
        $template['materials_count'] = count($template['material_details']);
    }
    unset($template);
    
    // تصفية القوالب: تجاهل القوالب التي لا تحتوي على مواد
    $unifiedTemplates = array_filter($unifiedTemplates, function($template) {
        return !empty($template['material_details']);
    });
    
    $templates = array_merge($templates, $unifiedTemplates);
}

// 1. قوالب العسل (القوالب التقليدية)
$honeyTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'product_templates'");
if (!empty($honeyTemplatesCheck)) {
    $productTemplatesHasMainSupplier = productionColumnExists('product_templates', 'main_supplier_id');
    $honeySql = "
        SELECT pt.*, 
               'honey' AS template_type,
               u.full_name AS creator_name";
    if ($productTemplatesHasMainSupplier) {
        $honeySql .= ",
               ms.name AS main_supplier_name,
               ms.phone AS main_supplier_phone";
    }
    $honeySql .= "
        FROM product_templates pt
        LEFT JOIN users u ON pt.created_by = u.id";
    if ($productTemplatesHasMainSupplier) {
        $honeySql .= "
        LEFT JOIN suppliers ms ON pt.main_supplier_id = ms.id";
    }
    $honeySql .= "
        WHERE pt.status = 'active'
        ORDER BY pt.created_at DESC";

    $honeyTemplates = $db->query($honeySql);
    foreach ($honeyTemplates as &$template) {
        $honeyQuantity = isset($template['honey_quantity']) ? (float)$template['honey_quantity'] : 0.0;
        $materialDetails = [
            [
                'type' => 'عسل',
                'quantity' => $honeyQuantity,
                'unit' => 'جرام',
                'material_type' => 'honey',
                'honey_variety' => $template['honey_variety'] ?? null
            ]
        ];
        $template['material_details'] = $materialDetails;
        $template['packaging_details'] = [];
        $template['packaging_count'] = 0;
        $template['materials_count'] = count($materialDetails);
    }
    unset($template);
    $templates = array_merge($templates, $honeyTemplates);
}

// 2. قوالب زيت الزيتون
$oliveOilTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'olive_oil_product_templates'");
if (!empty($oliveOilTemplatesCheck)) {
    $oliveOilTemplates = $db->query(
        "SELECT id, product_name, olive_oil_quantity, created_at, updated_at,
                'olive_oil' as template_type
         FROM olive_oil_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($oliveOilTemplates as &$template) {
        $oliveQuantity = isset($template['olive_oil_quantity']) ? (float)$template['olive_oil_quantity'] : 0.0;
        $materialDetails = [
            [
                'type' => 'زيت زيتون',
                'quantity' => $oliveQuantity,
                'unit' => 'لتر',
                'material_type' => 'olive_oil',
                'honey_variety' => null
            ]
        ];
        $template['material_details'] = $materialDetails;
        $template['packaging_details'] = [];
        $template['packaging_count'] = 0;
        $template['materials_count'] = count($materialDetails);
        $template['creator_name'] = '';
        $template['main_supplier_name'] = $template['main_supplier_name'] ?? '';
        $template['main_supplier_phone'] = $template['main_supplier_phone'] ?? '';
    }
    unset($template);
    $templates = array_merge($templates, $oliveOilTemplates);
}

// 3. قوالب شمع العسل
$beeswaxTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'beeswax_product_templates'");
if (!empty($beeswaxTemplatesCheck)) {
    $beeswaxTemplates = $db->query(
        "SELECT id, product_name, beeswax_weight, created_at,
                'beeswax' as template_type
         FROM beeswax_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($beeswaxTemplates as &$template) {
        $template['material_details'] = [
            [
                'type' => 'شمع عسل',
                'quantity' => $template['beeswax_weight'],
                'unit' => 'كجم',
                'material_type' => 'beeswax'
            ]
        ];
        $template['creator_name'] = '';
    }
    unset($template);
    $templates = array_merge($templates, $beeswaxTemplates);
}

// 4. قوالب المشتقات
$derivativesTemplatesCheck = $db->queryOne("SHOW TABLES LIKE 'derivatives_product_templates'");
if (!empty($derivativesTemplatesCheck)) {
    $derivativesTemplates = $db->query(
        "SELECT id, product_name, derivative_type, derivative_weight, created_at, updated_at,
                'derivatives' as template_type
         FROM derivatives_product_templates
         ORDER BY created_at DESC"
    );
    foreach ($derivativesTemplates as &$template) {
        // ترجمة نوع المشتق إلى العربية
        $derivativeTypeArabic = $template['derivative_type'];
        $derivativeTranslations = [
            'royal_jelly' => 'غذاء ملكات النحل',
            'propolis' => 'البروبوليس',
            'pollen' => 'حبوب اللقاح',
            'other' => 'مشتق آخر'
        ];
        if (isset($derivativeTranslations[$derivativeTypeArabic])) {
            $derivativeTypeArabic = $derivativeTranslations[$derivativeTypeArabic];
        }
        
        $derivativeQuantity = isset($template['derivative_weight']) ? (float)$template['derivative_weight'] : 0.0;
        $materialDetails = [
            [
                'type' => $derivativeTypeArabic,
                'quantity' => $derivativeQuantity,
                'unit' => 'كجم',
                'material_type' => 'derivatives',
                'honey_variety' => null
            ]
        ];
        $template['material_details'] = $materialDetails;
        $template['packaging_details'] = [];
        $template['packaging_count'] = 0;
        $template['materials_count'] = count($materialDetails);
        $template['creator_name'] = '';
        $template['main_supplier_name'] = $template['main_supplier_name'] ?? '';
        $template['main_supplier_phone'] = $template['main_supplier_phone'] ?? '';
    }
    unset($template);
    $templates = array_merge($templates, $derivativesTemplates);
}

// جلب أدوات التعبئة المستخدمة في نافذة إنشاء القالب
$packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$packagingMaterials = [];
if (!empty($packagingTableCheck)) {
    $packagingMaterials = $db->query(
        "SELECT id, name, type, quantity, unit FROM packaging_materials WHERE status = 'active' ORDER BY name"
    );
}

$productionReportsTodayDate = date('Y-m-d');
$productionReportsMonthStart = date('Y-m-01');
$productionReportsMonthEnd = date('Y-m-t');
if (strtotime($productionReportsMonthEnd) > strtotime($productionReportsTodayDate)) {
    $productionReportsMonthEnd = $productionReportsTodayDate;
}

$supplyCategoryLabels = [
    'honey' => 'العسل',
    'olive_oil' => 'زيت الزيتون',
    'beeswax' => 'شمع العسل',
    'derivatives' => 'المشتقات',
    'nuts' => 'المكسرات'
];
$supplyCategoryParam = isset($_GET['supply_category']) ? trim((string)$_GET['supply_category']) : '';
if ($supplyCategoryParam !== '' && !array_key_exists($supplyCategoryParam, $supplyCategoryLabels)) {
    $supplyCategoryParam = '';
}

$reportDayParam = isset($_GET['report_day']) ? trim((string)$_GET['report_day']) : '';
$reportFilterType = isset($_GET['report_type']) ? strtolower(trim((string)$_GET['report_type'])) : 'all';
if (!in_array($reportFilterType, ['all', 'packaging', 'raw'], true)) {
    $reportFilterType = 'all';
}
$reportFilterQuery = isset($_GET['report_query']) ? trim((string)$_GET['report_query']) : '';

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

$productionReportsMonth = getConsumptionSummary($productionReportsMonthStart, $productionReportsMonthEnd);
$productionReportsSelectedDay = getConsumptionSummary($selectedReportDay, $selectedReportDay);

$showPackagingReports = $reportFilterType === 'all' || $reportFilterType === 'packaging';
$showRawReports = $reportFilterType === 'all' || $reportFilterType === 'raw';

$filteredMonthPackagingItems = productionPageFilterItems($productionReportsMonth['packaging']['items'] ?? [], $reportFilterType, $reportFilterQuery, 'packaging');
$filteredMonthRawItems = productionPageFilterItems($productionReportsMonth['raw']['items'] ?? [], $reportFilterType, $reportFilterQuery, 'raw');

$filteredDayPackagingItems = productionPageFilterItems($productionReportsSelectedDay['packaging']['items'] ?? [], $reportFilterType, $reportFilterQuery, 'packaging');
$filteredDayRawItems = productionPageFilterItems($productionReportsSelectedDay['raw']['items'] ?? [], $reportFilterType, $reportFilterQuery, 'raw');

$dayPackagingTotals = productionPageAggregateTotals($filteredDayPackagingItems);
$dayRawTotals = productionPageAggregateTotals($filteredDayRawItems);
$dayNetCombined = round($dayPackagingTotals['net'] + $dayRawTotals['net'], 3);
$dayMovementsTotal = $dayPackagingTotals['movements'] + $dayRawTotals['movements'];
$dayRawSubTotals = productionPageBuildSubTotals($filteredDayRawItems);

if ($reportFilterType === 'all' && $reportFilterQuery === '') {
    $monthRawSubTotals = $productionReportsMonth['raw']['sub_totals'] ?? [];
} else {
    $monthRawSubTotals = productionPageBuildSubTotals($filteredMonthRawItems);
}

$supplyLogsMonth = getProductionSupplyLogs($productionReportsMonthStart, $productionReportsMonthEnd, $supplyCategoryParam !== '' ? $supplyCategoryParam : null);
$supplyLogsDay = getProductionSupplyLogs($selectedReportDay, $selectedReportDay, $supplyCategoryParam !== '' ? $supplyCategoryParam : null);

$supplyMonthTotalQuantity = 0.0;
$supplyMonthSuppliersSet = [];
foreach ($supplyLogsMonth as $logItem) {
    $supplyMonthTotalQuantity += isset($logItem['quantity']) ? (float)$logItem['quantity'] : 0.0;
    if (!empty($logItem['supplier_id'])) {
        $supplyMonthSuppliersSet['id_' . $logItem['supplier_id']] = true;
    } elseif (!empty($logItem['supplier_name'])) {
        $supplyMonthSuppliersSet['name_' . mb_strtolower(trim((string)$logItem['supplier_name']), 'UTF-8')] = true;
    }
}
$supplyMonthSuppliersCount = count($supplyMonthSuppliersSet);

$supplyDayTotalQuantity = 0.0;
foreach ($supplyLogsDay as $logItem) {
    $supplyDayTotalQuantity += isset($logItem['quantity']) ? (float)$logItem['quantity'] : 0.0;
}

$damageMonthPayload = productionPageBuildDamagePayload(
    $productionReportsMonth['packaging_damage'] ?? [],
    $productionReportsMonth['raw_damage'] ?? []
);
$damageMonthSummaryRows = $damageMonthPayload['summary'];
$damageMonthLogs = $damageMonthPayload['logs'];
$damageMonthTotal = $damageMonthPayload['total'];
$damageMonthEntries = $damageMonthPayload['entries'];
$damageMonthLatestLabel = $damageMonthPayload['latest_label'];

$damageDayPayload = productionPageBuildDamagePayload(
    $productionReportsSelectedDay['packaging_damage'] ?? [],
    $productionReportsSelectedDay['raw_damage'] ?? []
);
$damageDaySummaryRows = $damageDayPayload['summary'];
$damageDayLogs = $damageDayPayload['logs'];
$damageDayTotal = $damageDayPayload['total'];
$damageDayEntries = $damageDayPayload['entries'];
$damageDayLatestLabel = $damageDayPayload['latest_label'];

$hasActiveFilters = ($reportFilterType !== 'all') || ($reportFilterQuery !== '') || ($selectedReportDay !== $productionReportsTodayDate);

$selectedDayLabel = function_exists('formatDate') ? formatDate($selectedReportDay) : $selectedReportDay;
$monthRangeLabelStart = function_exists('formatDate') ? formatDate($productionReportsMonthStart) : $productionReportsMonthStart;
$monthRangeLabelEnd = function_exists('formatDate') ? formatDate($productionReportsMonthEnd) : $productionReportsMonthEnd;
$monthGeneratedAt = $productionReportsMonth['generated_at'] ?? date('Y-m-d H:i:s');
$dayGeneratedAt = $productionReportsSelectedDay['generated_at'] ?? date('Y-m-d H:i:s');

$activeFilterLabels = [];
if ($reportFilterType === 'packaging') {
    $activeFilterLabels[] = 'عرض أدوات التعبئة فقط';
} elseif ($reportFilterType === 'raw') {
    $activeFilterLabels[] = 'عرض المواد الخام فقط';
}
if ($reportFilterQuery !== '') {
    $activeFilterLabels[] = 'بحث: ' . $reportFilterQuery;
}

$additionalSuppliersOptions = [];
try {
    $suppliersStatusColumnCheck = $db->queryOne("SHOW COLUMNS FROM suppliers LIKE 'status'");
    if (!empty($suppliersStatusColumnCheck)) {
        $additionalSuppliersOptions = $db->query(
            "SELECT id, name, type FROM suppliers WHERE status = 'active' ORDER BY name ASC"
        );
    } else {
        $additionalSuppliersOptions = $db->query(
            "SELECT id, name, type FROM suppliers ORDER BY name ASC"
        );
    }
} catch (Exception $e) {
    error_log('Failed to load suppliers list: ' . $e->getMessage());
    $additionalSuppliersOptions = [];
}

$additionalSuppliersSelectSize = max(4, min(10, count($additionalSuppliersOptions)));

$productSpecifications = [];
$specificationsCount = 0;
$totalSpecificationsCount = 0;
try {
    $specificationsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_specifications'");
    if (!empty($specificationsTableCheck)) {
        $totalRow = $db->queryOne("SELECT COUNT(*) AS total_specs FROM product_specifications");
        if (is_array($totalRow) && isset($totalRow['total_specs'])) {
            $totalSpecificationsCount = (int) $totalRow['total_specs'];
        }

        $specSql = <<<SQL
        SELECT ps.*,
               creator.full_name AS creator_name,
               updater.full_name AS updater_name
        FROM product_specifications ps
        LEFT JOIN users creator ON ps.created_by = creator.id
        LEFT JOIN users updater ON ps.updated_by = updater.id
        ORDER BY COALESCE(ps.updated_at, ps.created_at) DESC
        LIMIT 12
        SQL;

        $productSpecifications = $db->query($specSql);
        if (!is_array($productSpecifications)) {
            $productSpecifications = [];
        }
    }
} catch (Throwable $productSpecificationsError) {
    error_log('production page: failed loading product specifications -> ' . $productSpecificationsError->getMessage());
    $productSpecifications = [];
}
$specificationsCount = is_countable($productSpecifications) ? count($productSpecifications) : 0;
if ($totalSpecificationsCount === 0 && $specificationsCount > 0) {
    $totalSpecificationsCount = $specificationsCount;
}

if (!function_exists('productionPageRenderConsumptionTable')) {
    function productionPageRenderConsumptionTable(array $items, bool $includeCategory = false): void
    {
        if (empty($items)) {
            echo '<div class="text-center text-muted py-4">لا توجد بيانات متاحة للفترة المحددة.</div>';
            return;
        }

        echo '<div class="table-responsive">';
        echo '<table class="table table-hover align-middle">';
        echo '<thead class="table-light"><tr>';
        echo '<th>المادة</th>';
        if ($includeCategory) {
            echo '<th>الفئة</th>';
        }
        echo '<th>الاستهلاك</th><th>الوارد</th><th>الصافي</th><th>الحركات</th>';
        echo '</tr></thead><tbody>';

        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'] ?? 'غير معروف', ENT_QUOTES, 'UTF-8');
            $category = htmlspecialchars($item['sub_category'] ?? '-', ENT_QUOTES, 'UTF-8');
            $totalOut = number_format((float)($item['total_out'] ?? 0), 3);
            $totalIn = number_format((float)($item['total_in'] ?? 0), 3);
            $net = number_format((float)($item['net'] ?? 0), 3);
            $movements = (int)($item['movements'] ?? 0);

            echo '<tr>';
            echo '<td>' . $name . '</td>';
            if ($includeCategory) {
                echo '<td><span class="badge bg-secondary text-white">' . $category . '</span></td>';
            }
            echo '<td>' . $totalOut . '</td>';
            echo '<td>' . $totalIn . '</td>';
            echo '<td>' . $net . '</td>';
            echo '<td>' . $movements . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }
}

if (!function_exists('productionPageSumMovements')) {
    function productionPageSumMovements(array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        return array_sum(array_map(static function ($row) {
            return (int)($row['movements'] ?? 0);
        }, $items));
    }
}

require_once __DIR__ . '/../../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i><?php echo isset($lang['production']) ? $lang['production'] : 'الإنتاج'; ?></h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="production-page">
    <?php 
    // التحقق من وضع التقارير للمدير
    $isReportsMode = defined('PRODUCTION_REPORTS_MODE') && PRODUCTION_REPORTS_MODE === true;
    $reportsSection = $_GET['section'] ?? '';
    $isReportsMode = $isReportsMode || ($reportsSection === 'reports' && ($currentUser['role'] ?? '') === 'manager');
    ?>
    
    <?php if (!$isReportsMode): ?>
    <header class="production-page-header">
        <div class="production-tab-toggle" role="tablist" aria-label="التنقل بين أقسام صفحة الإنتاج">
        <button type="button"
                class="btn btn-outline-primary production-tab-btn active"
                data-production-tab="records"
                aria-pressed="true"
                aria-controls="productionRecordsSection">
            <i class="bi bi-list-task me-1"></i>
            سجلات الإنتاج
        </button>
        <button type="button"
                class="btn btn-outline-primary production-tab-btn"
                data-production-tab="reports"
                aria-pressed="false"
                aria-controls="productionReportsSection">
            <i class="bi bi-graph-up-arrow me-1"></i>
            تقارير الإنتاج
        </button>
        <button type="button"
                class="btn btn-outline-primary production-tab-btn"
                data-production-tab="product_specs"
                aria-pressed="false"
                aria-controls="productionSpecsSection">
            <i class="bi bi-journal-text me-1"></i>
            وصفات المنتجات
        </button>
        </div>
    </header>
    <?php endif; ?>

<section id="productionRecordsSection" class="production-section <?php echo $isReportsMode ? 'd-none' : 'active'; ?>">

<?php if (!$isReportsMode): ?>
<!-- قسم قوالب المنتجات -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>قوالب المنتجات - إنشاء إنتاج من قالب</h5>
        <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-light btn-sm">
            <i class="bi bi-plus-circle me-2"></i>إدارة القوالب في مخزن الخامات
        </a>
    </div>
    <?php if (!empty($templates)): ?>
    <?php
    $materialIconMap = [
        'honey_filtered' => 'bi-droplet-fill',
        'honey_raw' => 'bi-droplet-half',
        'honey' => 'bi-droplet-fill',
        'olive_oil' => 'bi-bucket-fill',
        'beeswax' => 'bi-hexagon-fill',
        'derivatives' => 'bi-diagram-3',
        'nuts' => 'bi-nut',
        'other' => 'bi-collection-fill'
    ];
    $materialKeywordIconMap = [
        'عسل' => 'bi-droplet-fill',
        'شمع' => 'bi-hexagon-fill',
        'زيت' => 'bi-bucket-fill',
        'زيوت' => 'bi-bucket-fill',
        'مشتق' => 'bi-diagram-3',
        'لقاح' => 'bi-flower1',
        'حبوب' => 'bi-flower1',
        'غذاء' => 'bi-cup-hot',
        'مكسر' => 'bi-nut',
        'سكر' => 'bi-cup-straw',
        'ماء' => 'bi-droplet'
    ];
    ?>
    <div class="card-body template-grid">
        <?php foreach ($templates as $template): ?>
            <?php 
            $templateTypeLabels = [
                'unified' => 'متعدد المواد',
                'honey' => 'عسل',
                'olive_oil' => 'زيت زيتون',
                'beeswax' => 'شمع عسل',
                'derivatives' => 'مشتقات'
            ];
            $typeIconMap = [
                'unified' => 'bi-diagram-3',
                'honey' => 'bi-droplet-fill',
                'olive_oil' => 'bi-bucket-fill',
                'beeswax' => 'bi-hexagon-fill',
                'derivatives' => 'bi-bezier'
            ];
            $typeLabel = $templateTypeLabels[$template['template_type']] ?? 'غير محدد';
            $typeIcon = $typeIconMap[$template['template_type']] ?? 'bi-box-seam';
            $typeAccents = [
                'unified' => ['#0f172a', '#0f172a22'],
                'honey' => ['#f59e0b', '#f59e0b22'],
                'olive_oil' => ['#16a34a', '#16a34a22'],
                'beeswax' => ['#2563eb', '#2563eb22'],
                'derivatives' => ['#7c3aed', '#7c3aed22']
            ];
            $accentPair = $typeAccents[$template['template_type']] ?? ['#334155', '#33415522'];
            $materialDetails = is_array($template['material_details'] ?? null) ? $template['material_details'] : [];
            $materialsCount = (int)($template['materials_count'] ?? count($materialDetails));
            if ($materialsCount === 0 && !empty($materialDetails)) {
                $materialsCount = count($materialDetails);
            }
            $materialsPreview = array_slice($materialDetails, 0, 3);
            $hasMoreMaterials = $materialsCount > count($materialsPreview);
            $packagingDetails = is_array($template['packaging_details'] ?? null) ? $template['packaging_details'] : [];
            $packagingCount = (int)($template['packaging_count'] ?? count($packagingDetails));
            if ($packagingCount === 0 && !empty($packagingDetails)) {
                $packagingCount = count($packagingDetails);
            }
            $packagingPreview = array_slice($packagingDetails, 0, 2);
            $hasMorePackaging = $packagingCount > count($packagingPreview);
            $productsCount = (int)($template['products_count'] ?? 0);
            if ($productsCount <= 0) {
                $productsCount = $packagingCount > 0 ? $packagingCount : 1;
            }
            $mainSupplierName = trim((string)($template['main_supplier_name'] ?? ''));
            $mainSupplierPhone = trim((string)($template['main_supplier_phone'] ?? ''));
            $creatorName = trim((string)($template['creator_name'] ?? ''));
            $templateName = trim((string)($template['product_name'] ?? ''));
            if ($templateName === '') {
                $templateName = 'منتج غير مسمى';
            }
            $templateNotes = trim((string)($template['notes'] ?? ''));
            $updatedAtRaw = $template['updated_at'] ?? $template['created_at'] ?? null;
            $updatedAtDisplay = '—';
            $updatedAtIso = '';
            if (!empty($updatedAtRaw)) {
                $updatedTimestamp = strtotime((string)$updatedAtRaw);
                if ($updatedTimestamp !== false) {
                    $updatedAtDisplay = date('Y/m/d', $updatedTimestamp);
                    $updatedAtIso = date(DATE_ATOM, $updatedTimestamp);
                }
            }
            $cardAttributes = '';
            if ($updatedAtIso !== '') {
                $cardAttributes .= ' data-template-updated="' . htmlspecialchars($updatedAtIso, ENT_QUOTES, 'UTF-8') . '"';
            }
            if ($mainSupplierName !== '') {
                $cardAttributes .= ' data-template-supplier="' . htmlspecialchars($mainSupplierName, ENT_QUOTES, 'UTF-8') . '"';
            }
            $materialsCountLabel = $materialsCount;
            ?>
            <div class="template-card-modern"
                 style="--template-accent: <?php echo $accentPair[0]; ?>; --template-accent-light: <?php echo $accentPair[1]; ?>;"
                 data-template-id="<?php echo $template['id']; ?>"
                 data-template-name="<?php echo htmlspecialchars($templateName, ENT_QUOTES, 'UTF-8'); ?>"
                 data-template-type="<?php echo htmlspecialchars($template['template_type'] ?? 'legacy', ENT_QUOTES, 'UTF-8'); ?>"
                 <?php echo $cardAttributes; ?>
                 onclick="openCreateFromTemplateModal(this)">

                <div class="template-card-icon" aria-hidden="true">
                    <i class="bi <?php echo htmlspecialchars($typeIcon, ENT_QUOTES, 'UTF-8'); ?>"></i>
                </div>

                <span class="visually-hidden"><?php echo htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8'); ?></span>

                <h6 class="template-title mb-0 text-center"<?php echo $templateNotes !== '' ? ' title="' . htmlspecialchars($templateNotes, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                    <?php echo htmlspecialchars($templateName, ENT_QUOTES, 'UTF-8'); ?>
                </h6>

                <div class="template-card-cta text-center">
                    <span class="template-action-badge">
                        <i class="bi bi-lightning-charge me-2"></i>ابدأ الإنتاج الآن
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
            <h5 class="text-muted">لا توجد قوالب منتجات</h5>
            <p class="text-muted">قم بإنشاء قوالب المنتجات من صفحة مخزن الخامات</p>
            <a href="<?php echo getDashboardUrl('production'); ?>?page=raw_materials_warehouse" class="btn btn-primary">
                <i class="bi bi-box-seam me-2"></i>الذهاب إلى مخزن الخامات
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- جدول الإنتاج -->
<div class="card shadow-sm mt-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i><?php echo isset($lang['production_list']) ? $lang['production_list'] : 'قائمة الإنتاج'; ?> (<?php echo $totalProduction; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?php echo isset($lang['id']) ? $lang['id'] : 'رقم'; ?></th>
                        <th><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?></th>
                        <th><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?></th>
                        <th><?php echo isset($lang['worker']) ? $lang['worker'] : 'العامل'; ?></th>
                        <th><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?></th>
                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                        <th><?php echo isset($lang['batch_number']) ? $lang['batch_number'] : 'رقم التشغيلة'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($productions)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                <?php echo isset($lang['no_production']) ? $lang['no_production'] : 'لا توجد سجلات إنتاج'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($productions as $prod): ?>
                            <tr>
                                <td>#<?php echo $prod['id']; ?></td>
                                <td><?php echo htmlspecialchars($prod['product_name'] ?? 'منتج غير محدد'); ?></td>
                                <td><?php echo number_format($prod['quantity'] ?? 0, 2); ?></td>
                                <td><?php echo htmlspecialchars($prod['worker_name'] ?? $prod['worker_username'] ?? 'مستخدم غير محدد'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($prod[$dateColumn] ?? $prod['created_at'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        $status = $prod['status'] ?? 'pending';
                                        echo $status === 'approved' ? 'success' : 
                                            ($status === 'rejected' ? 'danger' : 
                                            ($status === 'completed' ? 'info' : 'warning')); 
                                    ?>">
                                        <?php echo isset($lang[$status]) ? $lang[$status] : ucfirst($status); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($prod['batch_number'])): ?>
                                        <div class="d-flex align-items-center flex-wrap gap-2">
                                            <span class="badge bg-secondary text-wrap" style="white-space: normal;"><?php echo htmlspecialchars($prod['batch_number']); ?></span>
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm print-batch-barcode"
                                                data-batch="<?php echo htmlspecialchars($prod['batch_number'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-product="<?php echo htmlspecialchars($prod['product_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quantity="<?php echo max(1, (int) round($prod['quantity'] ?? 1)); ?>"
                                                title="طباعة باركود التشغيلة"
                                            >
                                                <i class="bi bi-printer"></i>
                                                <span class="d-none d-lg-inline ms-1">طباعة</span>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">غير متوفر</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=production&p=<?php echo $pageNum - 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=production&p=1">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=production&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=production&p=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=production&p=<?php echo $pageNum + 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
            <div class="text-center mt-2 text-muted">
                <small>عرض <?php echo min($offset + 1, $totalProduction); ?> - <?php echo min($offset + $perPage, $totalProduction); ?> من أصل <?php echo $totalProduction; ?> سجل</small>
            </div>
        </nav>
        <?php endif; ?>
    </div>
</div>
<?php endif; // end if !$isReportsMode for templates section ?>
</section>

<?php if (!$isReportsMode): ?>
<section id="productionSpecsSection" class="production-section d-none">
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>مواصفات المنتجات (الوصفات المرجعية)</h5>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark">
                    <?php echo number_format($totalSpecificationsCount); ?>
                    مواصفة
                </span>
                <?php if (($currentUser['role'] ?? '') === 'manager'): ?>
                    <a href="<?php echo getDashboardUrl('manager'); ?>?page=product_specifications" class="btn btn-light btn-sm">
                        <i class="bi bi-gear me-1"></i>إدارة المواصفات
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if ($specificationsCount === 0): ?>
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    لم يتم تسجيل مواصفات بعد. تواصل مع المشرف لإضافة الوصفات المرجعية.
                </div>
            <?php else: ?>
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>اسم المنتج</th>
                                <th width="24%">المواد الخام</th>
                                <th width="24%">أدوات التعبئة</th>
                                <th width="22%">ملاحظات الإنشاء</th>
                                <th>آخر تحديث</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productSpecifications as $specification): ?>
                                <tr>
                                    <td data-label="اسم المنتج">
                                        <strong><?php echo htmlspecialchars($specification['product_name'] ?? '—'); ?></strong>
                                    </td>
                                    <td data-label="المواد الخام">
                                        <div class="text-muted small">الأوزان / الكميات</div>
                                        <div class="mt-1" style="white-space: pre-line;">
                                            <?php
                                            $rawMaterials = $specification['raw_materials'] ?? '';
                                            echo $rawMaterials !== null && $rawMaterials !== ''
                                                ? nl2br(htmlspecialchars($rawMaterials, ENT_QUOTES, 'UTF-8'))
                                                : '<span class="text-muted">—</span>';
                                            ?>
                                        </div>
                                    </td>
                                    <td data-label="أدوات التعبئة">
                                        <div class="text-muted small">الأعداد / الأحجام</div>
                                        <div class="mt-1" style="white-space: pre-line;">
                                            <?php
                                            $packaging = $specification['packaging'] ?? '';
                                            echo $packaging !== null && $packaging !== ''
                                                ? nl2br(htmlspecialchars($packaging, ENT_QUOTES, 'UTF-8'))
                                                : '<span class="text-muted">—</span>';
                                            ?>
                                        </div>
                                    </td>
                                    <td data-label="ملاحظات الإنشاء" style="white-space: pre-line;">
                                        <?php
                                        $notes = $specification['notes'] ?? '';
                                        echo $notes !== null && $notes !== ''
                                            ? nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'))
                                            : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <td data-label="آخر تحديث">
                                        <?php
                                            $updatedAt = $specification['updated_at'] ?? null;
                                            $createdAt = $specification['created_at'] ?? null;
                                            $timestamp = $updatedAt ?: $createdAt;
                                            $formattedTimestamp = '—';
                                            if ($timestamp) {
                                                if (function_exists('formatDateTime')) {
                                                    $formattedTimestamp = htmlspecialchars(formatDateTime($timestamp));
                                                } else {
                                                    $formattedTimestamp = htmlspecialchars(date('Y-m-d H:i', strtotime($timestamp)));
                                                }
                                            }
                                            echo $formattedTimestamp;
                                        ?>
                                        <div class="text-muted small mt-1">
                                            <?php if (!empty($specification['updated_by'])): ?>
                                                بواسطة <?php echo htmlspecialchars($specification['updater_name'] ?? 'غير محدد'); ?>
                                            <?php elseif (!empty($specification['created_by'])): ?>
                                                مسجل بواسطة <?php echo htmlspecialchars($specification['creator_name'] ?? 'غير محدد'); ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalSpecificationsCount > $specificationsCount): ?>
                    <div class="text-muted small mt-3 d-flex justify-content-between flex-wrap gap-2">
                        <span>يعرض آخر <?php echo number_format($specificationsCount); ?> من إجمالي <?php echo number_format($totalSpecificationsCount); ?> مواصفة.</span>
                        <a class="text-decoration-none" href="<?php echo getDashboardUrl('manager'); ?>?page=product_specifications">
                            <i class="bi bi-box-arrow-up-right me-1"></i>عرض جميع المواصفات
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; // end if !$isReportsMode for specs section ?>


<section id="productionReportsSection" class="production-section <?php echo $isReportsMode ? 'active' : 'd-none'; ?>">
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end" action="<?php echo htmlspecialchars(getDashboardUrl($currentUser['role'] ?? 'production')); ?>">
                <input type="hidden" name="page" value="production">
                <input type="hidden" name="section" value="reports">
                <div class="col-lg-3 col-md-4">
                    <label class="form-label fw-semibold"><i class="bi bi-calendar-day me-2"></i>اليوم المراد تحليله</label>
                    <input type="date"
                           class="form-control"
                           name="report_day"
                           value="<?php echo htmlspecialchars($selectedReportDay); ?>"
                           min="<?php echo htmlspecialchars($productionReportsMonthStart); ?>"
                           max="<?php echo htmlspecialchars($productionReportsMonthEnd); ?>">
                </div>
                <div class="col-lg-3 col-md-4">
                    <label class="form-label fw-semibold">نوع المواد</label>
                    <select class="form-select" name="report_type">
                        <option value="all" <?php echo $reportFilterType === 'all' ? 'selected' : ''; ?>>الكل</option>
                        <option value="packaging" <?php echo $reportFilterType === 'packaging' ? 'selected' : ''; ?>>أدوات التعبئة</option>
                        <option value="raw" <?php echo $reportFilterType === 'raw' ? 'selected' : ''; ?>>المواد الخام</option>
                    </select>
                </div>
                <div class="col-lg-3 col-md-4">
                    <label class="form-label fw-semibold">قسم التوريدات</label>
                    <select class="form-select" name="supply_category">
                        <option value="">جميع الأقسام</option>
                        <?php foreach ($supplyCategoryLabels as $categoryKey => $categoryLabel): ?>
                            <option value="<?php echo htmlspecialchars($categoryKey); ?>"
                                <?php echo $supplyCategoryParam === $categoryKey ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categoryLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-4 col-md-6">
                    <label class="form-label fw-semibold">بحث عن مادة</label>
                    <input type="search"
                           class="form-control"
                           name="report_query"
                           placeholder="مثال: عبوات زجاجية أو عسل سداسي"
                           value="<?php echo htmlspecialchars($reportFilterQuery); ?>">
                </div>
                <div class="col-lg-2 col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel me-2"></i>تطبيق
                    </button>
                </div>
                <?php if ($hasActiveFilters): ?>
                    <div class="col-12 d-flex justify-content-end">
                        <a href="<?php echo htmlspecialchars(getDashboardUrl($currentUser['role'] ?? 'production') . '?page=production&section=reports'); ?>"
                           class="btn btn-link text-decoration-none">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>إعادة التعيين
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (!empty($activeFilterLabels)): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 mb-4">
            <i class="bi bi-funnel-fill"></i>
            <span>تم تطبيق التصفية التالية:</span>
            <?php foreach ($activeFilterLabels as $label): ?>
                <span class="badge bg-primary text-white"><?php echo htmlspecialchars($label); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="card production-report-card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-month me-2"></i>ملخص الشهر الحالي</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($monthRangeLabelStart); ?>
                        إلى
                        <?php echo htmlspecialchars($monthRangeLabelEnd); ?>
                    </p>
                </div>
                <span class="badge bg-light text-primary border border-primary-subtle">
                    آخر تحديث: <?php echo htmlspecialchars($monthGeneratedAt); ?>
                </span>
            </div>
            <div class="production-summary-grid mt-3">
                <?php if ($showPackagingReports): ?>
                    <div class="summary-card">
                        <span class="summary-label">إجمالي استهلاك التعبئة</span>
                        <span class="summary-value text-primary">
                            <?php echo number_format((float)($productionReportsMonth['packaging']['total_out'] ?? 0), 3); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($showRawReports): ?>
                    <div class="summary-card">
                        <span class="summary-label">إجمالي استهلاك المواد الخام</span>
                        <span class="summary-value text-primary">
                            <?php echo number_format((float)($productionReportsMonth['raw']['total_out'] ?? 0), 3); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="summary-card">
                    <span class="summary-label">الصافي الشهري</span>
                    <span class="summary-value text-success">
                        <?php
                        $monthNet = (float)($productionReportsMonth['packaging']['net'] ?? 0) + (float)($productionReportsMonth['raw']['net'] ?? 0);
                        echo number_format($monthNet, 3);
                        ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">إجمالي الحركات</span>
                    <span class="summary-value text-secondary">
                        <?php
                        $monthMovements = productionPageSumMovements($productionReportsMonth['packaging']['items'] ?? [])
                            + productionPageSumMovements($productionReportsMonth['raw']['items'] ?? []);
                        echo number_format($monthMovements);
                        ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="card production-report-card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-truck"></i>
                <span>توريدات المواد خلال الفترة</span>
                <span class="badge bg-light text-dark border">
                    <?php echo htmlspecialchars($monthRangeLabelStart); ?> - <?php echo htmlspecialchars($monthRangeLabelEnd); ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3 small text-dark">
                <span>إجمالي الكميات: <strong class="text-primary"><?php echo number_format($supplyMonthTotalQuantity, 3); ?></strong></span>
                <span>عدد الموردين: <strong><?php echo $supplyMonthSuppliersCount; ?></strong></span>
                <span>
                    القسم:
                    <?php if ($supplyCategoryParam !== '' && isset($supplyCategoryLabels[$supplyCategoryParam])): ?>
                        <span class="badge bg-primary text-white"><?php echo htmlspecialchars($supplyCategoryLabels[$supplyCategoryParam]); ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary text-white">جميع الأقسام</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>القسم</th>
                            <th>المورد</th>
                            <th>الكمية</th>
                            <th>الوصف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplyLogsMonth)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox me-2"></i>لا توجد توريدات ضمن الفترة المحددة
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supplyLogsMonth as $supplyLog): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <?php echo htmlspecialchars(formatDate($supplyLog['recorded_at'] ?? '')); ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars(formatTime($supplyLog['recorded_at'] ?? '')); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                            $categoryKey = $supplyLog['material_category'] ?? '';
                                            echo htmlspecialchars($supplyCategoryLabels[$categoryKey] ?? $categoryKey ?: '-');
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplyLog['supplier_name'] ?: '-'); ?></td>
                                    <td>
                                        <span class="fw-semibold text-primary">
                                            <?php echo number_format((float)($supplyLog['quantity'] ?? 0), 3); ?>
                                        </span>
                                        <span class="text-muted small"><?php echo htmlspecialchars($supplyLog['unit'] ?? 'كجم'); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($supplyLog['details'])): ?>
                                            <?php echo htmlspecialchars($supplyLog['details']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card production-report-card shadow-sm mb-4">
        <div class="card-header bg-danger text-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon"></i>
                <span>سجل التوالف في المخازن</span>
                <span class="badge bg-light text-danger border border-light-subtle">
                    <?php echo htmlspecialchars($monthRangeLabelStart); ?> - <?php echo htmlspecialchars($monthRangeLabelEnd); ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-3 small text-white">
                <span>إجمالي التلفيات: <strong class="text-white"><?php echo number_format($damageMonthTotal, 3); ?></strong></span>
                <span>عدد السجلات: <strong class="text-white"><?php echo number_format($damageMonthEntries); ?></strong></span>
                <?php if ($damageMonthLatestLabel !== ''): ?>
                    <span>آخر تسجيل: <strong class="text-white"><?php echo htmlspecialchars($damageMonthLatestLabel); ?></strong></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($damageMonthSummaryRows)): ?>
                <div class="production-summary-grid">
                    <?php foreach ($damageMonthSummaryRows as $row): ?>
                        <div class="summary-card">
                            <span class="summary-label"><?php echo htmlspecialchars($row['label'] ?? 'قسم'); ?></span>
                            <span class="summary-value text-danger"><?php echo number_format((float)($row['total'] ?? 0), 3); ?></span>
                            <small class="text-muted">سجلات: <?php echo number_format((int)($row['entries'] ?? 0)); ?></small>
                            <?php if (!empty($row['last_recorded_at'])): ?>
                                <small class="text-muted">
                                    <?php echo htmlspecialchars(productionPageFormatDateTimeLabel($row['last_recorded_at'])); ?>
                                    <?php if (!empty($row['last_recorded_by'])): ?>
                                        — <?php echo htmlspecialchars($row['last_recorded_by']); ?>
                                    <?php endif; ?>
                                </small>
                            <?php elseif (!empty($row['last_recorded_by'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($row['last_recorded_by']); ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light border border-danger-subtle text-muted mb-0">
                    <i class="bi bi-check2-circle me-2 text-success"></i>لا توجد سجلات تلفيات خلال الفترة المحددة.
                </div>
            <?php endif; ?>

            <ul class="nav nav-pills mt-4" id="damageLogTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="damage-log-month-tab" data-bs-toggle="tab" data-bs-target="#damage-log-month" type="button" role="tab" aria-controls="damage-log-month" aria-selected="true">
                        تلفيات الشهر
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="damage-log-day-tab" data-bs-toggle="tab" data-bs-target="#damage-log-day" type="button" role="tab" aria-controls="damage-log-day" aria-selected="false">
                        تلفيات يوم <?php echo htmlspecialchars($selectedDayLabel); ?>
                    </button>
                </li>
            </ul>

            <div class="tab-content mt-3" id="damageLogTabsContent">
                <div class="tab-pane fade show active" id="damage-log-month" role="tabpanel" aria-labelledby="damage-log-month-tab">
                    <?php productionPageRenderDamageLogsTable($damageMonthLogs, 'لا توجد سجلات تلفيات ضمن الفترة الشهرية المحددة.'); ?>
                </div>
                <div class="tab-pane fade" id="damage-log-day" role="tabpanel" aria-labelledby="damage-log-day-tab">
                    <?php if (!empty($damageDaySummaryRows)): ?>
                        <div class="production-summary-grid mb-3">
                            <?php foreach ($damageDaySummaryRows as $row): ?>
                                <div class="summary-card">
                                    <span class="summary-label"><?php echo htmlspecialchars($row['label'] ?? 'قسم'); ?></span>
                                    <span class="summary-value text-danger"><?php echo number_format((float)($row['total'] ?? 0), 3); ?></span>
                                    <small class="text-muted">سجلات: <?php echo number_format((int)($row['entries'] ?? 0)); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php
                        $dayEmptyMessage = 'لا توجد سجلات تلفيات مسجلة في هذا اليوم.';
                        productionPageRenderDamageLogsTable($damageDayLogs, $dayEmptyMessage);
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card production-report-card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <i class="bi bi-truck me-2"></i>توريدات المواد خلال يوم <?php echo htmlspecialchars($selectedDayLabel); ?>
            </div>
            <div class="small text-dark">
                إجمالي الكميات: <strong class="text-primary"><?php echo number_format($supplyDayTotalQuantity, 3); ?></strong>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>التاريخ</th>
                            <th>القسم</th>
                            <th>المورد</th>
                            <th>الكمية</th>
                            <th>الوصف</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplyLogsDay)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox me-2"></i>لا توجد توريدات مسجلة في هذا اليوم
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($supplyLogsDay as $supplyLog): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold">
                                            <?php echo htmlspecialchars(formatDate($supplyLog['recorded_at'] ?? '')); ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars(formatTime($supplyLog['recorded_at'] ?? '')); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                            $categoryKey = $supplyLog['material_category'] ?? '';
                                            echo htmlspecialchars($supplyCategoryLabels[$categoryKey] ?? $categoryKey ?: '-');
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplyLog['supplier_name'] ?: '-'); ?></td>
                                    <td>
                                        <span class="fw-semibold text-primary">
                                            <?php echo number_format((float)($supplyLog['quantity'] ?? 0), 3); ?>
                                        </span>
                                        <span class="text-muted small"><?php echo htmlspecialchars($supplyLog['unit'] ?? 'كجم'); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($supplyLog['details'])): ?>
                                            <?php echo htmlspecialchars($supplyLog['details']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($showPackagingReports): ?>
        <div class="card production-report-card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة خلال الشهر</span>
            </div>
            <div class="card-body">
                <?php productionPageRenderConsumptionTable($filteredMonthPackagingItems); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showRawReports): ?>
        <div class="card production-report-card shadow-sm mb-4">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-droplet-half me-2"></i>المواد الخام خلال الشهر</span>
            </div>
            <div class="card-body">
                <?php if (!empty($monthRawSubTotals)): ?>
                    <div class="mb-3">
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($monthRawSubTotals as $subTotal): ?>
                                <span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($subTotal['label'] ?? 'غير مصنف'); ?>:
                                    <?php echo number_format((float)($subTotal['total_out'] ?? 0), 3); ?>
                                    (صافي <?php echo number_format((float)($subTotal['net'] ?? 0), 3); ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php productionPageRenderConsumptionTable($filteredMonthRawItems, true); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card production-report-card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-bar-chart-fill me-2"></i>استهلاك يوم <?php echo htmlspecialchars($selectedDayLabel); ?></h4>
                    <p class="text-muted mb-0">
                        ضمن فترة الشهر: <?php echo htmlspecialchars($monthRangeLabelStart); ?> إلى <?php echo htmlspecialchars($monthRangeLabelEnd); ?>
                    </p>
                </div>
                <span class="badge bg-light text-primary border border-primary-subtle">
                    آخر تحديث: <?php echo htmlspecialchars($dayGeneratedAt); ?>
                </span>
            </div>
            <div class="production-summary-grid mt-3">
                <?php if ($showPackagingReports): ?>
                    <div class="summary-card">
                        <span class="summary-label">استهلاك أدوات التعبئة</span>
                        <span class="summary-value text-primary">
                            <?php echo number_format($dayPackagingTotals['total_out'], 3); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($showRawReports): ?>
                    <div class="summary-card">
                        <span class="summary-label">استهلاك المواد الخام</span>
                        <span class="summary-value text-primary">
                            <?php echo number_format($dayRawTotals['total_out'], 3); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <div class="summary-card">
                    <span class="summary-label">الصافي اليومي</span>
                    <span class="summary-value text-success">
                        <?php echo number_format($dayNetCombined, 3); ?>
                    </span>
                </div>
                <div class="summary-card">
                    <span class="summary-label">إجمالي الحركات</span>
                    <span class="summary-value text-secondary">
                        <?php echo number_format($dayMovementsTotal); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($showPackagingReports): ?>
        <div class="card production-report-card shadow-sm mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة خلال يوم <?php echo htmlspecialchars($selectedDayLabel); ?></span>
            </div>
            <div class="card-body">
                <?php productionPageRenderConsumptionTable($filteredDayPackagingItems); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showRawReports): ?>
        <div class="card production-report-card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-droplet-half me-2"></i>المواد الخام خلال يوم <?php echo htmlspecialchars($selectedDayLabel); ?></span>
            </div>
            <div class="card-body">
                <?php if (!empty($dayRawSubTotals)): ?>
                    <div class="mb-3">
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($dayRawSubTotals as $subTotal): ?>
                                <span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($subTotal['label'] ?? 'غير مصنف'); ?>:
                                    <?php echo number_format((float)($subTotal['total_out'] ?? 0), 3); ?>
                                    (صافي <?php echo number_format((float)($subTotal['net'] ?? 0), 3); ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php productionPageRenderConsumptionTable($filteredDayRawItems, true); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
</div>

<!-- Modal إنشاء إنتاج من قالب -->
<div class="modal fade" id="createFromTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable production-template-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>إنشاء تشغيلة إنتاج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createFromTemplateForm">
                <input type="hidden" name="action" value="create_from_template">
                <input type="hidden" name="template_id" id="template_id">
                <input type="hidden" name="template_mode" id="template_mode" value="advanced">
                <input type="hidden" name="template_type" id="template_type" value="">
                <div class="modal-body production-template-body">
                    <!-- معلومات المنتج والتشغيلة -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-box-seam me-2"></i>معلومات المنتج والتشغيلة</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">اسم المنتج</label>
                                <input type="text" class="form-control" id="template_product_name" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">الكمية المراد إنتاجها <span class="text-danger">*</span></label>
                                <input type="number" name="quantity" class="form-control" min="1" required value="1">
                                <small class="text-muted d-block mt-1">سيتم إنشاء رقم تشغيلة واحد (LOT) لجميع المنتجات، مع استخدام تاريخ اليوم تلقائياً.</small>
                            </div>
                        </div>
                        <input type="hidden" name="production_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <!-- الموردون لكل مادة -->
                    <div class="mb-3 section-block d-none" id="templateSuppliersWrapper">
                        <h6 class="text-primary section-heading">
                            <i class="bi bi-truck me-2"></i>الموردون لكل مادة <span class="text-danger">*</span>
                        </h6>
                        <p class="text-muted small mb-3" id="templateSuppliersHint">يرجى اختيار المورد المناسب لكل مادة سيتم استخدامها في هذه التشغيلة.</p>
                        <div class="row g-3" id="templateSuppliersContainer"></div>
                    </div>

                    <!-- عمال الإنتاج الحاضرون -->
                    <div class="mb-3 section-block">
                        <h6 class="text-primary section-heading"><i class="bi bi-people me-2"></i>عمال الإنتاج الحاضرون</h6>
                        <?php
                        // جلب عمال الإنتاج الحاضرين داخل المنشأة
                        $presentWorkersToday = [];
                        $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                        if (!empty($attendanceTableCheck)) {
                            $presentWorkersToday = $db->query(
                                "SELECT DISTINCT u.id, u.username, u.full_name 
                                 FROM attendance_records ar
                                 JOIN users u ON ar.user_id = u.id
                                 WHERE ar.date = ? 
                                 AND ar.check_in_time IS NOT NULL 
                                 AND u.role = 'production' 
                                 AND u.status = 'active'
                                 ORDER BY ar.check_in_time DESC",
                                [date('Y-m-d')]
                            );
                        }
                        ?>
                        <?php if (!empty($presentWorkersToday)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>عمال الإنتاج الحاضرون حاليا:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($presentWorkersToday as $worker): ?>
                                        <li><?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <small class="text-muted">سيتم ربط التشغيلة تلقائياً بجميع عمال الإنتاج الحاضرين حالياً.</small>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                لا يوجد عمال إنتاج حاضرون حالياً. سيتم ربط التشغيلة بالعامل الحالي فقط.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="user_id" value="<?php echo $currentUser['id']; ?>">
                    <?php endif; ?>
                    
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>إنشاء التشغيلة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal طباعة الباركودات -->
<div class="modal fade" id="printBarcodesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-printer me-2"></i>طباعة الباركودات</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    تم إنشاء <strong id="barcode_quantity">0</strong> سجل إنتاج بنجاح مع أرقام التشغيلة
                </div>
                <div class="mb-3">
                    <label class="form-label">اسم المنتج</label>
                    <input type="text" class="form-control" id="barcode_product_name" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">عدد الباركودات المراد طباعتها</label>
                    <input type="number" class="form-control" id="barcode_print_quantity" min="1" value="1">
                    <small class="text-muted">سيتم طباعة نفس رقم التشغيلة بعدد المرات المحدد</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">أرقام التشغيلة</label>
                    <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                        <div id="batch_numbers_list"></div>
                    </div>
                    <div id="barcodeFallbackMessages" class="d-none mt-3"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-outline-primary d-none" id="openDirectPrintBtn">
                    <i class="bi bi-printer me-2"></i>فتح صفحة الطباعة
                </button>
                <button type="button" class="btn btn-primary" id="sendBarcodeToTelegramBtn" onclick="printBarcodes()">
                    <i class="bi bi-send-check me-2"></i>إرسال رابط الطباعة
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة إنتاج -->
<div class="modal fade" id="addProductionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add_production']) ? $lang['add_production'] : 'إضافة إنتاج'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addProductionForm">
                <input type="hidden" name="action" value="add_production">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?> <span class="text-danger">*</span></label>
                            <select name="product_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_product']) ? $lang['select_product'] : 'اختر المنتج'; ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label d-block">الوحدة</label>
                            <div class="form-control-plaintext fw-semibold">كجم</div>
                            <input type="hidden" name="unit" value="kg">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'تاريخ الإنتاج'; ?> <span class="text-danger">*</span></label>
                            <input type="date" name="production_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <?php if ($currentUser['role'] !== 'production' && $userIdColumn): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['worker']) ? $lang['worker'] : 'العامل'; ?> <span class="text-danger">*</span></label>
                            <select name="user_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_worker']) ? $lang['select_worker'] : 'اختر العامل'; ?></option>
                                <?php foreach ($workers as $worker): ?>
                                    <option value="<?php echo $worker['id']; ?>">
                                        <?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['materials_used']) ? $lang['materials_used'] : 'المواد المستخدمة'; ?></label>
                            <textarea name="materials_used" class="form-control" rows="3" placeholder="<?php echo isset($lang['materials_used_placeholder']) ? $lang['materials_used_placeholder'] : 'أدرج المواد المستخدمة في العملية...'; ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'ملاحظات'; ?></label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="<?php echo isset($lang['notes_placeholder']) ? $lang['notes_placeholder'] : 'ملاحظات إضافية...'; ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل إنتاج -->
<div class="modal fade" id="editProductionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?php echo isset($lang['edit_production']) ? $lang['edit_production'] : 'تعديل إنتاج'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editProductionForm">
                <input type="hidden" name="action" value="update_production">
                <input type="hidden" name="production_id" id="edit_production_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?> <span class="text-danger">*</span></label>
                            <select name="product_id" id="edit_product_id" class="form-select" required>
                                <option value=""><?php echo isset($lang['select_product']) ? $lang['select_product'] : 'اختر المنتج'; ?></option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="quantity" id="edit_quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label d-block">الوحدة</label>
                            <div class="form-control-plaintext fw-semibold" id="edit_unit_display">كجم</div>
                            <input type="hidden" name="unit" id="edit_unit" value="kg">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['date']) ? $lang['date'] : 'تاريخ الإنتاج'; ?> <span class="text-danger">*</span></label>
                            <input type="date" name="production_date" id="edit_production_date" class="form-control" required>
                        </div>
                        <?php if (in_array($currentUser['role'], ['accountant', 'manager'])): ?>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="pending"><?php echo isset($lang['pending']) ? $lang['pending'] : 'معلق'; ?></option>
                                <option value="approved"><?php echo isset($lang['approved']) ? $lang['approved'] : 'معتمد'; ?></option>
                                <option value="rejected"><?php echo isset($lang['rejected']) ? $lang['rejected'] : 'مرفوض'; ?></option>
                                <option value="completed"><?php echo isset($lang['completed']) ? $lang['completed'] : 'مكتمل'; ?></option>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['materials_used']) ? $lang['materials_used'] : 'المواد المستخدمة'; ?></label>
                            <textarea name="materials_used" id="edit_materials_used" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isset($lang['notes']) ? $lang['notes'] : 'ملاحظات'; ?></label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['update']) ? $lang['update'] : 'تحديث'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$honeyStockDataForJs = [];
$honeyVarietyTracker = [];

$ensureHoneySupplierBucket = static function (int $supplierId) use (&$honeyStockDataForJs, &$honeyVarietyTracker): void {
    if (!isset($honeyStockDataForJs[$supplierId])) {
        $honeyStockDataForJs[$supplierId] = [
            'all' => [],
            'honey_raw' => [],
            'honey_filtered' => [],
        ];
    }
    if (!isset($honeyVarietyTracker[$supplierId])) {
        $honeyVarietyTracker[$supplierId] = [];
    }
};

$registerHoneyVariety = static function (int $supplierId, string $variety, float $rawQty = 0.0, float $filteredQty = 0.0) use (&$ensureHoneySupplierBucket, &$honeyVarietyTracker): void {
    if ($supplierId <= 0) {
        return;
    }

    $ensureHoneySupplierBucket($supplierId);

    $normalizedVariety = trim($variety);
    if ($normalizedVariety === '') {
        $normalizedVariety = 'غير محدد';
    }

    $trackerKey = function_exists('mb_strtolower')
        ? mb_strtolower($normalizedVariety, 'UTF-8')
        : strtolower($normalizedVariety);

    if (!isset($honeyVarietyTracker[$supplierId][$trackerKey])) {
        $honeyVarietyTracker[$supplierId][$trackerKey] = [
            'variety' => $normalizedVariety,
            'raw_quantity' => 0.0,
            'filtered_quantity' => 0.0,
        ];
    }

    $honeyVarietyTracker[$supplierId][$trackerKey]['raw_quantity'] += $rawQty;
    $honeyVarietyTracker[$supplierId][$trackerKey]['filtered_quantity'] += $filteredQty;
};

try {
    $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
    if (!empty($honeyStockTableCheck)) {
        $honeyStockRows = $db->query("
            SELECT 
                supplier_id, 
                honey_variety, 
                COALESCE(raw_honey_quantity, 0) AS raw_quantity, 
                COALESCE(filtered_honey_quantity, 0) AS filtered_quantity
            FROM honey_stock
        ");
        foreach ($honeyStockRows as $row) {
            $supplierId = (int)($row['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }
            $varietyName = (string)($row['honey_variety'] ?? '');
            $rawQuantity = (float)($row['raw_quantity'] ?? 0);
            $filteredQuantity = (float)($row['filtered_quantity'] ?? 0);
            $registerHoneyVariety($supplierId, $varietyName, $rawQuantity, $filteredQuantity);
        }
    }

    $batchNumbersTableCheck = $db->queryOne("SHOW TABLES LIKE 'batch_numbers'");
    if (!empty($batchNumbersTableCheck)) {
        $batchHoneyRows = $db->query("
            SELECT DISTINCT
                honey_supplier_id AS supplier_id,
                honey_variety
            FROM batch_numbers
            WHERE honey_supplier_id IS NOT NULL
              AND honey_supplier_id > 0
              AND honey_variety IS NOT NULL
              AND honey_variety <> ''
        ");

        foreach ($batchHoneyRows as $row) {
            $supplierId = (int)($row['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }
            $varietyName = (string)$row['honey_variety'];
            $registerHoneyVariety($supplierId, $varietyName, 0.0, 0.0);
        }
    }

    foreach ($honeyVarietyTracker as $supplierId => $varieties) {
        $allEntries = array_values($varieties);
        $rawEntries = array_values(array_filter($allEntries, static function ($entry) {
            return ($entry['raw_quantity'] ?? 0) > 0;
        }));
        $filteredEntries = array_values(array_filter($allEntries, static function ($entry) {
            return ($entry['filtered_quantity'] ?? 0) > 0;
        }));

        if (empty($rawEntries)) {
            $rawEntries = $allEntries;
        }
        if (empty($filteredEntries)) {
            $filteredEntries = $allEntries;
        }

        $honeyStockDataForJs[$supplierId]['all'] = $allEntries;
        $honeyStockDataForJs[$supplierId]['honey_raw'] = $rawEntries;
        $honeyStockDataForJs[$supplierId]['honey_filtered'] = $filteredEntries;
    }
} catch (Exception $honeyDataException) {
    error_log('Production honey stock fetch error: ' . $honeyDataException->getMessage());
}
?>

<script>
window.productionSuppliers = <?php
$suppliersForJs = is_array($suppliers) ? $suppliers : [];
echo json_encode(array_map(function($supplier) {
    return [
        'id' => (int)($supplier['id'] ?? 0),
        'name' => $supplier['name'] ?? '',
        'type' => $supplier['type'] ?? ''
    ];
}, $suppliersForJs), JSON_UNESCAPED_UNICODE);
?>;
window.honeyStockData = <?php echo json_encode($honeyStockDataForJs, JSON_UNESCAPED_UNICODE); ?>;
let currentTemplateMode = 'advanced';
const TEMPLATE_DETAILS_BASE_URL = '<?php echo addslashes(getRelativeUrl('dashboard/production.php')); ?>';
const PRINT_BARCODE_URL = '<?php echo addslashes(getRelativeUrl('print_barcode.php')); ?>';
const SEND_BARCODE_TELEGRAM_URL = '<?php echo addslashes(getRelativeUrl('api/production/send_barcode_link.php')); ?>';

const HONEY_COMPONENT_TYPES = ['honey_raw', 'honey_filtered', 'honey_general', 'honey_main'];

function isHoneyComponent(component) {
    if (!component) {
        return false;
    }
    const type = (component.type || '').toString();
    if (type && HONEY_COMPONENT_TYPES.includes(type)) {
        return true;
    }
    const requiresVariety = component.requires_variety;
    if (
        requiresVariety === true
        || requiresVariety === 1
        || requiresVariety === '1'
        || (typeof requiresVariety === 'string' && requiresVariety.trim().toLowerCase() === 'true')
        || (typeof requiresVariety === 'string' && requiresVariety.trim().toLowerCase() === 'yes')
    ) {
        return true;
    }
    const key = (component.key || '').toString();
    return key.startsWith('honey_');
}

function getSuppliersForComponent(component) {
    const suppliers = window.productionSuppliers || [];
    if (!component) {
        return suppliers;
    }
    const type = (component.type || '').toString();
    const key = (component.key || '').toString();

    const filterByTypes = (allowedTypes) => suppliers.filter(supplier => allowedTypes.includes(supplier.type));

    if (isHoneyComponent(component)) {
        return filterByTypes(['honey']);
    }

    if (type === 'packaging' || key.startsWith('pack_')) {
        return filterByTypes(['packaging']);
    }

    if (type === 'olive_oil' || key.startsWith('olive')) {
        return filterByTypes(['olive_oil']);
    }

    if (type === 'beeswax' || key.startsWith('beeswax')) {
        return filterByTypes(['beeswax']);
    }

    if (type === 'derivatives' || key.startsWith('derivative')) {
        return filterByTypes(['derivatives']);
    }

    if (type === 'nuts' || key.startsWith('nuts')) {
        return filterByTypes(['nuts']);
    }

    return suppliers;
}

function normalizeSupplierKey(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const numeric = Number(value);
    if (!Number.isNaN(numeric) && numeric > 0) {
        return String(numeric);
    }
    return String(value);
}

function populateHoneyVarietyOptions(selectEl, supplierId, component) {
    if (!selectEl) {
        return;
    }

    const normalizeVariety = (value) => {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).trim();
    };

    const normalizedKey = normalizeSupplierKey(supplierId);
    selectEl.innerHTML = '';

    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.disabled = true;
    placeholderOption.selected = true;
    placeholderOption.textContent = normalizedKey ? 'اختر نوع العسل' : 'اختر المورد أولاً';
    selectEl.appendChild(placeholderOption);

    // استخراج نوع العسل من component.name أو component.material_name إذا كان يحتوي على " - "
    let componentHoneyVariety = component?.honey_variety ?? component?.variety ?? component?.material_type ?? '';
    
    // إذا لم يكن نوع العسل محدداً مباشرة، استخرجه من اسم المادة
    if (!componentHoneyVariety) {
        const materialName = component?.name || component?.material_name || component?.label || '';
        
        // إذا كان الاسم يحتوي على " - "، استخرج النوع
        if (materialName.includes(' - ')) {
            const parts = materialName.split(' - ', 2);
            if (parts.length === 2) {
                const materialBaseName = parts[0].trim();
                const materialType = parts[1].trim();
                // إذا كان اسم المادة الأساسي يحتوي على "عسل"، استخدم النوع
                if (materialBaseName.includes('عسل') || materialBaseName.toLowerCase().includes('honey')) {
                    componentHoneyVariety = materialType;
                }
            }
        }
        // إذا كان الاسم يبدأ بـ "عسل "، استخرج النوع
        else if (materialName.startsWith('عسل ') && materialName.length > 5) {
            const parts = materialName.split(' ', 2);
            if (parts.length === 2) {
                componentHoneyVariety = parts[1].trim();
            }
        }
    }
    
    const defaultValue = normalizeVariety(
        selectEl.dataset.defaultValue !== undefined
            ? selectEl.dataset.defaultValue
            : componentHoneyVariety
    );
    const defaultValueLower = defaultValue.toLocaleLowerCase('ar');

    if (!normalizedKey) {
        if (defaultValue !== '') {
            const fallbackOption = document.createElement('option');
            fallbackOption.value = defaultValue;
            fallbackOption.dataset.raw = 0;
            fallbackOption.dataset.filtered = 0;
            fallbackOption.textContent = `${defaultValue} — (القيمة المعرّفة في القالب)`;
            fallbackOption.selected = true;
            selectEl.appendChild(fallbackOption);
            placeholderOption.selected = false;
            selectEl.disabled = false;
        } else {
            selectEl.disabled = true;
        }
        return;
    }

    const honeyData = window.honeyStockData || {};
    const supplierData = honeyData[normalizedKey] ?? honeyData[String(parseInt(normalizedKey, 10))] ?? null;
    const componentType = (component?.type || '').toString();

    let items = [];
    if (supplierData) {
        if (componentType === 'honey_raw' && Array.isArray(supplierData.honey_raw) && supplierData.honey_raw.length) {
            items = supplierData.honey_raw;
        } else if (componentType === 'honey_filtered' && Array.isArray(supplierData.honey_filtered) && supplierData.honey_filtered.length) {
            items = supplierData.honey_filtered;
        } else if (Array.isArray(supplierData.all)) {
            items = supplierData.all;
        }
    }

    const aggregated = {};
    items.forEach(item => {
        const varietyName = (item && item.variety) ? String(item.variety) : 'غير محدد';
        if (!aggregated[varietyName]) {
            aggregated[varietyName] = {
                raw: 0,
                filtered: 0
            };
        }
        aggregated[varietyName].raw += Number(item.raw_quantity ?? 0);
        aggregated[varietyName].filtered += Number(item.filtered_quantity ?? 0);
    });

    const entries = Object.entries(aggregated);
    let matchedOption = null;

    if (entries.length === 0) {
        placeholderOption.textContent = 'لا توجد كميات متاحة لدى المورد المختار';
    } else {
        selectEl.disabled = false;

        entries.forEach(([varietyName, quantities]) => {
            const rawQty = Number(quantities.raw ?? 0);
            const filteredQty = Number(quantities.filtered ?? 0);
            const parts = [];

            if (componentType === 'honey_filtered') {
                parts.push(`المصفى: ${filteredQty.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} كجم`);
            } else if (componentType === 'honey_raw') {
                parts.push(`الخام: ${rawQty.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} كجم`);
            } else {
                if (rawQty > 0) {
                    parts.push(`الخام: ${rawQty.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} كجم`);
                }
                if (filteredQty > 0) {
                    parts.push(`المصفى: ${filteredQty.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} كجم`);
                }
            }

            const option = document.createElement('option');
            const normalizedVariety = normalizeVariety(varietyName);
            option.value = normalizedVariety;
            option.dataset.raw = rawQty;
            option.dataset.filtered = filteredQty;
            option.textContent = parts.length
                ? `${normalizedVariety} — ${parts.join(' | ')}`
                : normalizedVariety;
            selectEl.appendChild(option);

            // التحقق من تطابق نوع العسل المحدد مسبقاً في القالب
            if (!matchedOption && defaultValue !== '' && normalizedVariety.toLocaleLowerCase('ar') === defaultValueLower) {
                matchedOption = option;
            }
        });
    }

    if (!matchedOption && defaultValue !== '') {
        const fallbackOption = document.createElement('option');
        fallbackOption.value = defaultValue;
        fallbackOption.dataset.raw = 0;
        fallbackOption.dataset.filtered = 0;
        fallbackOption.textContent = entries.length === 0
            ? `${defaultValue} — (القيمة المعرّفة في القالب)`
            : `${defaultValue} — (غير متوفر في المخزون الحالي)`;
        selectEl.appendChild(fallbackOption);
        matchedOption = fallbackOption;
        placeholderOption.selected = false;
        selectEl.disabled = false;
    }

    if (matchedOption) {
        matchedOption.selected = true;
        selectEl.value = matchedOption.value;
        placeholderOption.selected = false;
        // إذا تم تحديد نوع العسل تلقائياً من القالب، قم بتشغيل حدث change
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (entries.length === 0) {
        // إذا لم يكن نوع العسل متوفراً في المخزون لكنه موجود في القالب، أضفه مع تحذير
        if (defaultValue !== '') {
            const warningOption = document.createElement('option');
            warningOption.value = defaultValue;
            warningOption.dataset.raw = 0;
            warningOption.dataset.filtered = 0;
            warningOption.textContent = `${defaultValue} — (غير متوفر في المخزون الحالي - يرجى إضافة المخزون أولاً)`;
            warningOption.style.color = '#dc3545';
            selectEl.appendChild(warningOption);
            warningOption.selected = true;
            selectEl.value = defaultValue;
            selectEl.disabled = false;
            placeholderOption.selected = false;
        } else {
            selectEl.disabled = true;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('[data-production-tab]');
    const sections = {
        records: document.getElementById('productionRecordsSection'),
        reports: document.getElementById('productionReportsSection'),
        product_specs: document.getElementById('productionSpecsSection')
    };

    tabButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const target = button.getAttribute('data-production-tab');
            if (!target || !sections[target]) {
                return;
            }

            tabButtons.forEach(function(btn) {
                btn.classList.remove('active');
                btn.setAttribute('aria-pressed', 'false');
            });

            Object.keys(sections).forEach(function(key) {
                if (sections[key]) {
                    sections[key].classList.add('d-none');
                }
            });

            button.classList.add('active');
            button.setAttribute('aria-pressed', 'true');
            sections[target].classList.remove('d-none');

            const scrollTarget = document.querySelector('.production-tab-toggle') || sections[target];
            if (scrollTarget) {
                scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    });

    const params = new URLSearchParams(window.location.search);
    const shouldShowReports = params.get('section') === 'reports'
        || params.has('report_day')
        || params.has('report_type')
        || params.has('report_query');
    if (shouldShowReports) {
        const reportsButton = document.querySelector('[data-production-tab="reports"]');
        if (reportsButton) {
            reportsButton.click();
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.print-batch-barcode').forEach(function(button) {
        button.addEventListener('click', function() {
            const batchNumber = button.getAttribute('data-batch') || '';
            if (!batchNumber) {
                alert('رقم التشغيلة غير متوفر للطباعة.');
                return;
            }
            const productName = button.getAttribute('data-product') || '';
            const quantityAttr = parseInt(button.getAttribute('data-quantity'), 10);
            const defaultQuantity = Number.isFinite(quantityAttr) && quantityAttr > 0 ? quantityAttr : 1;
            showBarcodeModal(batchNumber, productName, defaultQuantity);
        });
    });
});

function openUrlInAppModalOrWindow(url, options = {}) {
    if (!url) {
        console.warn('Attempted to open an empty URL.');
        return null;
    }
    const opener = options.opener instanceof Element
        ? options.opener
        : (document.activeElement instanceof Element ? document.activeElement : null);
    if (typeof window.openInAppModal === 'function') {
        window.openInAppModal(url, { opener: opener });
        return null;
    }
    const features = typeof options.features === 'string' ? options.features : 'noopener';
    const newWindow = window.open(url, '_blank', features);
    if (newWindow && typeof newWindow.focus === 'function' && options.focus !== false) {
        newWindow.focus();
    }
    return newWindow;
}

function showBarcodeModal(batchNumber, productName, defaultQuantity) {
    const modalElement = document.getElementById('printBarcodesModal');
    const quantity = defaultQuantity > 0 ? defaultQuantity : 1;

    window.batchNumbersToPrint = [batchNumber];

    const productNameInput = document.getElementById('barcode_product_name');
    if (productNameInput) {
        productNameInput.value = productName || '';
    }

    const quantityText = document.getElementById('barcode_quantity');
    if (quantityText) {
        quantityText.textContent = quantity;
    }

    const quantityInput = document.getElementById('barcode_print_quantity');
    if (quantityInput) {
        quantityInput.value = quantity;
    }

    const batchListContainer = document.getElementById('batch_numbers_list');
    if (batchListContainer) {
        batchListContainer.innerHTML = `
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                <strong>رقم التشغيلة:</strong> ${batchNumber}<br>
                <small>ستتم طباعة نفس رقم التشغيلة بعدد ${quantity} باركود</small>
            </div>
        `;
    }

    if (modalElement && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    } else {
        const fallbackUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${quantity}&print=1`;
        openUrlInAppModalOrWindow(fallbackUrl, { opener: document.activeElement instanceof Element ? document.activeElement : null });
    }

    if (!window.batchPrintInfo || window.batchPrintInfo.batch_number !== batchNumber) {
        bootstrapBatchPrintContext(batchNumber, quantity, productName);
    }
}

function bootstrapBatchPrintContext(batchNumber, fallbackQuantity, productName) {
    if (!batchNumber) {
        return;
    }

    if (typeof TEMPLATE_DETAILS_BASE_URL !== 'string' || TEMPLATE_DETAILS_BASE_URL === '') {
        return;
    }

    const requestUrl = `${TEMPLATE_DETAILS_BASE_URL}?page=production&ajax=bootstrap_batch_print&batch=${encodeURIComponent(batchNumber)}`;

    fetch(requestUrl, { cache: 'no-store', credentials: 'same-origin' })
        .then((response) => response.json().catch(() => ({})))
        .then((data) => {
            if (!data || data.success !== true) {
                console.warn('تعذر تهيئة بيانات الطباعة للتشغيلة', data && data.error ? data.error : data);
                return;
            }

            const metadata = (typeof data.metadata === 'object' && data.metadata !== null)
                ? data.metadata
                : {};

            metadata.batch_number = metadata.batch_number || batchNumber;

            if (data.context_token) {
                metadata.context_token = data.context_token;
            }

            if (data.telegram_enabled !== undefined) {
                metadata.telegram_enabled = data.telegram_enabled;
            }

            window.batchPrintInfo = metadata;

            if (!Array.isArray(window.batchNumbersToPrint) || window.batchNumbersToPrint.length === 0) {
                window.batchNumbersToPrint = [batchNumber];
            }

            const parsedQuantity = parseInt(data.quantity, 10);
            const resolvedQuantity = Number.isFinite(parsedQuantity) && parsedQuantity > 0
                ? parsedQuantity
                : fallbackQuantity;
            const resolvedProductName = data.product_name || productName || '';

            const productNameInput = document.getElementById('barcode_product_name');
            if (productNameInput && resolvedProductName !== '') {
                productNameInput.value = resolvedProductName;
            }

            const quantityText = document.getElementById('barcode_quantity');
            if (quantityText && resolvedQuantity > 0) {
                quantityText.textContent = resolvedQuantity;
            }

            const quantityInput = document.getElementById('barcode_print_quantity');
            if (quantityInput && resolvedQuantity > 0) {
                quantityInput.value = resolvedQuantity;
            }
        })
        .catch((error) => {
            console.error('Failed to prepare batch print context', error);
        });
}

function renderTemplateSuppliers(details) {
    const cacheKey = details?.cache_key;
    if (!details || !details.success) {
        return;
    }
    if (cacheKey) {
        window.templateDetailsCache = window.templateDetailsCache || {};
        window.templateDetailsCache[cacheKey] = details;
    }
    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const hintText = document.getElementById('templateSuppliersHint');
    const summaryWrapper = document.getElementById('templateComponentsSummary');
    const summaryGrid = document.getElementById('templateComponentsSummaryGrid');
    const materialsInfoBox = document.getElementById('templateMaterialsInfo');

    if (!container || !wrapper || !modeInput) {
        return;
    }

    const components = Array.isArray(details?.components) ? details.components : [];
    const normalizeBoolean = (value) => {
        if (value === true || value === false) {
            return value;
        }
        if (typeof value === 'number') {
            return value === 1;
        }
        if (typeof value === 'string') {
            const normalized = value.trim().toLowerCase();
            return ['1', 'true', 'yes', 'y', 't'].includes(normalized);
        }
        return false;
    };
    const requiresHoneyVariety = (component) => {
        if (!component) {
            return false;
        }
        return normalizeBoolean(component.requires_variety);
    };

    container.innerHTML = '';
    if (summaryGrid) {
        summaryGrid.innerHTML = '';
    }
    if (summaryWrapper) {
        summaryWrapper.classList.add('d-none');
    }

    if (components.length === 0) {
        if (materialsInfoBox) {
            materialsInfoBox.innerHTML = '<div class="text-muted small">لا توجد مواد مرتبطة بهذا القالب حالياً.</div>';
        }
        wrapper.classList.remove('d-none');
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    لا توجد مواد مرتبطة بالقالب. يرجى تحديث القالب وإضافة المواد المطلوبة.
                </div>
            </div>
        `;
        if (hintText) {
        hintText.textContent = 'لا توجد مواد لعرضها.';
        }
        currentTemplateMode = 'advanced';
        modeInput.value = 'advanced';
        return;
    }

    const determineComponentType = (component) => {
        if (!component) {
            return 'generic';
        }
        const type = (component.type || '').toString().toLowerCase();
        if (type) {
            if (requiresHoneyVariety(component) && !HONEY_COMPONENT_TYPES.includes(type)) {
                return 'honey_general';
            }
            return type;
        }
        if (requiresHoneyVariety(component)) {
            return 'honey_general';
        }
        const key = (component.key || '').toString().toLowerCase();
        const name = ((component.name || component.label || '').toString().toLowerCase());
        
        // فحص العسل من الاسم أيضاً
        if (key.startsWith('honey_') || name.includes('عسل')) {
            if (name.includes('مصفى') || name.includes('filtered') || key.includes('filtered')) {
                return 'honey_filtered';
            }
            if (name.includes('خام') || name.includes('raw') || key.includes('raw')) {
                return 'honey_raw';
            }
            return 'honey_general';
        }
        
        if (key.startsWith('pack_')) return 'packaging';
        if (key.startsWith('raw_')) return 'raw_general';
        if (key.startsWith('olive')) return 'olive_oil';
        if (key.startsWith('beeswax')) return 'beeswax';
        if (key.startsWith('derivative')) return 'derivatives';
        if (key.startsWith('nuts')) return 'nuts';
        return 'generic';
    };

    const accentColors = {
        packaging: '#0dcaf0',
        honey_raw: '#f59e0b',
        honey_filtered: '#fb923c',
        honey_main: '#facc15',
        honey_general: '#facc15',
        olive_oil: '#22c55e',
        beeswax: '#a855f7',
        derivatives: '#6366f1',
        nuts: '#d97706',
        raw_general: '#3b82f6',
        generic: '#2563eb',
        default: '#2563eb'
    };

    const typeLabelsMap = {
        packaging: 'أداة تعبئة',
        honey_raw: 'عسل خام',
        honey_filtered: 'عسل مصفى',
        honey_main: 'عسل',
        honey_general: 'عسل',
        olive_oil: 'زيت زيتون',
        beeswax: 'شمع عسل',
        derivatives: 'مشتقات',
        nuts: 'مكسرات',
        raw_general: 'مادة خام',
        generic: 'مكوّن'
    };

    const componentIcons = {
        packaging: 'bi-box-seam',
        honey_raw: 'bi-droplet-half',
        honey_filtered: 'bi-droplet',
        honey_main: 'bi-bezier',
        honey_general: 'bi-bezier',
        olive_oil: 'bi-bezier2',
        beeswax: 'bi-hexagon',
        derivatives: 'bi-intersect',
        nuts: 'bi-record-circle',
        raw_general: 'bi-diagram-3',
        generic: 'bi-diagram-2'
    };

    const formatComponentQuantity = (component) => {
        if (!component) {
            return 'غير محدد';
        }
        const quantityDisplay = component.quantity_display || component.quantity_label || component.quantity_text;
        if (quantityDisplay && String(quantityDisplay).trim() !== '') {
            return quantityDisplay;
        }
        const unit = component.unit || component.unit_label || component.unit_name || '';
        const numericSource = component.quantity_per_unit ?? component.quantity ?? component.amount ?? null;
        if (numericSource !== null && numericSource !== undefined) {
            const numericValue = Number(numericSource);
            if (!Number.isNaN(numericValue)) {
                const formatted = numericValue.toLocaleString('ar-EG', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 3
                });
                return unit ? `${formatted} ${unit}` : formatted;
            }
            const rawString = String(numericSource).trim();
            if (rawString !== '') {
                return unit ? `${rawString} ${unit}` : rawString;
            }
        }
        return unit !== '' ? unit : 'غير محدد';
    };

    if (materialsInfoBox) {
        const infoItemsHtml = components.map((component, index) => {
            const canonicalType = determineComponentType(component);
            const typeLabel = typeLabelsMap[canonicalType] || typeLabelsMap.generic;
            const quantityLabel = formatComponentQuantity(component);
            const description = component.description || component.details || '';
            const name = component.name || component.label || `مكوّن رقم ${index + 1}`;
            return `
                <div class="materials-info-item">
                    <div class="materials-info-header">
                        <span class="materials-info-name">${name}</span>
                        <span class="materials-info-type badge bg-light text-primary border">
                            ${typeLabel}
                        </span>
                    </div>
                    <div class="materials-info-meta text-muted">
                        <i class="bi bi-basket me-1"></i>
                        <span>الكمية لكل تشغيلة: ${quantityLabel}</span>
                    </div>
                    ${description ? `<div class="materials-info-note text-muted">${description}</div>` : ''}
                </div>
            `;
        }).join('');

        materialsInfoBox.innerHTML = `
            <div class="materials-info-summary text-muted mb-2">
                تم تحميل ${components.length} من المواد والمكوّنات الخاصة بالقالب المحدد.
            </div>
            <div class="materials-info-list">
                ${infoItemsHtml}
            </div>
        `;
    }

    const stats = {
        total: components.length,
        packaging: 0,
        honey: 0,
        raw: 0,
        special: 0
    };

    components.forEach(component => {
        const canonicalType = determineComponentType(component);
        if (canonicalType === 'packaging') {
            stats.packaging += 1;
            return;
        }
        if (
            isHoneyComponent(component)
            || canonicalType === 'honey_raw'
            || canonicalType === 'honey_filtered'
            || canonicalType === 'honey_main'
            || canonicalType === 'honey_general'
            || requiresHoneyVariety(component)
        ) {
            stats.honey += 1;
            stats.raw += 1;
            return;
        }
        if (['olive_oil', 'beeswax', 'derivatives', 'nuts', 'raw_general'].includes(canonicalType) || canonicalType.startsWith('raw_')) {
            stats.raw += 1;
            return;
        }
        stats.special += 1;
    });

    stats.special = Math.max(0, stats.total - stats.raw - stats.packaging);

    if (summaryWrapper && summaryGrid) {
        const summaryItems = [
            { key: 'total', label: 'إجمالي المكوّنات', value: stats.total, icon: 'bi-collection' },
            { key: 'raw', label: 'مواد خام / أساسية', value: stats.raw, icon: 'bi-droplet-half' },
            { key: 'packaging', label: 'أدوات تعبئة', value: stats.packaging, icon: 'bi-box' },
            { key: 'honey', label: 'يتطلب نوع عسل', value: stats.honey, icon: 'bi-stars' },
            { key: 'special', label: 'مكوّنات خاصة', value: stats.special, icon: 'bi-puzzle' }
        ].filter(item => item.value > 0 || item.key === 'total');

        summaryGrid.innerHTML = summaryItems.map(item => `
            <div class="template-summary-item">
                <span class="template-summary-icon">
                    <i class="bi ${item.icon}"></i>
                </span>
                <div class="template-summary-content">
                    <span class="template-summary-value">${item.value}</span>
                    <span class="template-summary-label">${item.label}</span>
                </div>
            </div>
        `).join('');

        summaryWrapper.classList.remove('d-none');
    }

    const createChip = (iconClass, text) => {
        const chip = document.createElement('span');
        chip.className = 'component-card-chip';
        chip.innerHTML = `<i class="bi ${iconClass} me-1"></i>${text}`;
        return chip;
    };

    const resolveComponentKey = (component) => {
        if (!component) {
            return 'component_' + Math.random().toString(36).slice(2);
        }
        if (!component.__resolvedKey) {
            component.__resolvedKey = component.key || component.name || ('component_' + Math.random().toString(36).slice(2));
        }
        return component.__resolvedKey;
    };

    const honeyComponentEntries = components
        .filter(component => {
            const canonicalType = determineComponentType(component);
            const honeyVarietyRequired = requiresHoneyVariety(component);
            const componentName = ((component.name || component.label || '').toString().toLowerCase());
            const componentKeyLower = ((component.key || component.name || '').toString().toLowerCase());
            const isHoneyByNameOrKey = componentName.includes('عسل') || componentKeyLower.includes('honey');
            
            return (
                isHoneyComponent(component)
                || honeyVarietyRequired
                || ['honey_raw', 'honey_filtered', 'honey_main', 'honey_general'].includes(canonicalType)
                || isHoneyByNameOrKey
            );
        })
        .map(component => ({
            component,
            key: resolveComponentKey(component)
        }));

    const honeyGroup = {
        entries: honeyComponentEntries,
        baseEntry: honeyComponentEntries[0] || null,
        extraEntries: honeyComponentEntries.slice(1),
        renderAggregated: honeyComponentEntries.length > 0,
        processedKeys: new Set()
    };

    components.forEach(function(component) {
        let canonicalType = determineComponentType(component);
        const componentKey = resolveComponentKey(component);
        const honeyVarietyRequired = requiresHoneyVariety(component);
        if (honeyVarietyRequired && !HONEY_COMPONENT_TYPES.includes(canonicalType)) {
            canonicalType = 'honey_general';
        }
        // فحص شامل لتحديد ما إذا كان المكوّن عسل
        const componentName = ((component.name || component.label || '').toString().toLowerCase());
        const componentKeyLower = (componentKey || '').toString().toLowerCase();
        const isHoneyByNameOrKey = componentName.includes('عسل') || componentKeyLower.includes('honey');
        
        const isHoneyType = isHoneyComponent(component)
            || honeyVarietyRequired
            || canonicalType === 'honey_raw'
            || canonicalType === 'honey_filtered'
            || canonicalType === 'honey_main'
            || canonicalType === 'honey_general'
            || isHoneyByNameOrKey;

        // تجميع جميع مكوّنات العسل في بطاقة واحدة فقط
        if (isHoneyType) {
            // إذا كان هناك مكوّنات عسل وتم بالفعل عرض بطاقة العسل
            if (honeyGroup.baseEntry && componentKey !== honeyGroup.baseEntry.key) {
                return; // تخطي المكوّنات الإضافية
            }
            // إذا كان هذا هو المكوّن الأول من العسل، تأكد من أنه لم يتم عرضه من قبل
            if (honeyGroup.baseEntry && componentKey === honeyGroup.baseEntry.key) {
                if (honeyGroup.processedKeys.has(componentKey)) {
                    return; // تم عرضه بالفعل
                }
                honeyGroup.processedKeys.add(componentKey);
            }
        }

        const isAggregatedHoneyCard = isHoneyType
            && honeyGroup.baseEntry
            && componentKey === honeyGroup.baseEntry.key
            && honeyGroup.renderAggregated;

        const aggregatedEntries = isAggregatedHoneyCard ? honeyGroup.entries : [];
        const extraHoneyEntries = isAggregatedHoneyCard ? honeyGroup.extraEntries : [];

        const effectiveType = isAggregatedHoneyCard ? 'honey_main' : canonicalType;
        const safeTypeClass = effectiveType.replace(/[^a-z0-9_-]/g, '') || 'generic';

        const col = document.createElement('div');
        col.className = 'col-12 col-lg-6';

        const card = document.createElement('div');
        card.className = `component-card component-type-${safeTypeClass}`;
        card.style.setProperty('--component-accent', accentColors[effectiveType] || accentColors.default);

        if (!isHoneyType) {
            const header = document.createElement('div');
            header.className = 'component-card-header';

            const title = document.createElement('span');
            title.className = 'component-card-title';
            title.textContent = component.name || component.label || 'مكوّن';

            const badge = document.createElement('span');
            badge.className = 'component-card-badge';
            badge.textContent = typeLabelsMap[effectiveType] || typeLabelsMap.generic;

            header.appendChild(title);
            header.appendChild(badge);
            card.appendChild(header);

            const meta = document.createElement('div');
            meta.className = 'component-card-meta';
            const metaIcon = document.createElement('i');
            metaIcon.className = `bi ${componentIcons[effectiveType] || componentIcons.generic} me-2`;
            meta.appendChild(metaIcon);
            const metaText = document.createElement('span');
            metaText.textContent = component.description || 'لا توجد تفاصيل إضافية.';
            meta.appendChild(metaText);
            card.appendChild(meta);

            const chipsWrapper = document.createElement('div');
            chipsWrapper.className = 'component-card-chips';
            if (component.default_supplier) {
                chipsWrapper.appendChild(createChip('bi-person-check', 'مورد مقترح'));
            }
            if (chipsWrapper.children.length > 0) {
                card.appendChild(chipsWrapper);
            }
        } else {
            card.classList.add('component-card-compact');
            // إضافة عنوان للمكوّنات المجمعة
            if (isAggregatedHoneyCard && aggregatedEntries.length > 0) {
                const header = document.createElement('div');
                header.className = 'component-card-header mb-2';
                const title = document.createElement('span');
                title.className = 'component-card-title';
                if (aggregatedEntries.length === 1) {
                    title.textContent = aggregatedEntries[0].component.name || aggregatedEntries[0].component.label || 'مكوّن عسل';
                } else {
                    title.textContent = 'مواد العسل (' + aggregatedEntries.length + ' مكوّن)';
                }
                header.appendChild(title);
                card.appendChild(header);
                
                // إضافة معلومات المكوّنات
                const meta = document.createElement('div');
                meta.className = 'component-card-meta mb-2';
                const metaList = aggregatedEntries.map(entry => {
                    const name = entry.component.name || entry.component.label || 'مكوّن';
                    const qty = entry.component.quantity || entry.component.amount || '';
                    return name + (qty ? ' (' + qty + ')' : '');
                }).join('، ');
                meta.innerHTML = '<i class="bi bi-stars me-2"></i><span>' + metaList + '</span>';
                card.appendChild(meta);
            }
        }

        const controlLabel = document.createElement('label');
        controlLabel.className = 'form-label fw-semibold small text-muted mb-1';
        if (isHoneyType) {
            // لمكوّنات العسل، استخدم دائماً "مورد العسل"
            controlLabel.textContent = aggregatedEntries.length > 1 
                ? 'مورد العسل (سيتم تطبيقه على جميع مكوّنات العسل)'
                : 'مورد العسل';
        } else {
            const typeLabel = typeLabelsMap[effectiveType] || 'المادة';
            controlLabel.textContent = 'مورد ' + typeLabel;
        }
        card.appendChild(controlLabel);

        const select = document.createElement('select');
        select.className = 'form-select form-select-sm component-supplier-select';
        select.name = 'material_suppliers[' + componentKey + ']';
        select.dataset.role = 'component-supplier';
        select.required = component.required !== false;
        select.dataset.componentType = canonicalType || component.type || '';

        const placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = component.placeholder || 'اختر المورد';
        select.appendChild(placeholderOption);

        // لمكوّنات العسل، استخدم موردين العسل فقط
        let suppliersForComponent = getSuppliersForComponent(component);
        let suppliersList = [];
        
        if (isHoneyType) {
            // لمكوّنات العسل، استخدم موردين العسل فقط حتى لو كان عددهم 0
            suppliersList = suppliersForComponent;
            // تأكد من أن الموردين المفلترين هم فقط موردين العسل
            const allSuppliers = window.productionSuppliers || [];
            suppliersList = allSuppliers.filter(supplier => supplier.type === 'honey');
        } else {
            // للمكوّنات الأخرى، استخدم الموردين المحددين أو جميع الموردين كـ fallback
            suppliersList = suppliersForComponent.length ? suppliersForComponent : (window.productionSuppliers || []);
        }
        let autoSelectSupplierId = null;

        suppliersList.forEach(function(supplier) {
            const option = document.createElement('option');
            option.value = supplier.id;
            option.textContent = supplier.name;
            if (component.default_supplier && parseInt(component.default_supplier, 10) === supplier.id) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        if (!component.default_supplier && suppliersList.length === 1) {
            autoSelectSupplierId = suppliersList[0].id;
        }

        if (suppliersList.length === 0) {
            const noSupplierOption = document.createElement('option');
            noSupplierOption.value = '';
            noSupplierOption.disabled = true;
            noSupplierOption.textContent = 'لا يوجد مورد مناسب - راجع قائمة الموردين.';
            select.appendChild(noSupplierOption);
        }

        card.appendChild(select);

        if (isHoneyType) {
            const honeyWrapper = document.createElement('div');
            honeyWrapper.className = 'mt-1';

            const honeyLabel = document.createElement('label');
            honeyLabel.className = 'form-label fw-bold mb-1';
            honeyLabel.textContent = 'نوع العسل من المورد المختار';

            const honeySelect = document.createElement('select');
            honeySelect.className = 'form-select form-select-sm';
            honeySelect.name = 'material_honey_varieties[' + componentKey + ']';
            honeySelect.dataset.role = 'honey-variety';
            honeySelect.required = true;
            honeySelect.disabled = true;
            // تحديد نوع العسل من القالب المحدد مسبقاً
            // استخراج نوع العسل من component.name أو component.material_name إذا كان يحتوي على " - "
            let defaultHoneyVariety = component.honey_variety || component.variety || component.material_type || '';
            
            // إذا لم يكن نوع العسل محدداً مباشرة، استخرجه من اسم المادة
            if (!defaultHoneyVariety) {
                const materialName = component.name || component.material_name || component.label || '';
                
                // إذا كان الاسم يحتوي على " - "، استخرج النوع
                if (materialName.includes(' - ')) {
                    const parts = materialName.split(' - ', 2);
                    if (parts.length === 2) {
                        const materialBaseName = parts[0].trim();
                        const materialType = parts[1].trim();
                        // إذا كان اسم المادة الأساسي يحتوي على "عسل"، استخدم النوع
                        if (materialBaseName.includes('عسل') || materialBaseName.toLowerCase().includes('honey')) {
                            defaultHoneyVariety = materialType;
                        }
                    }
                }
                // إذا كان الاسم يبدأ بـ "عسل "، استخرج النوع
                else if (materialName.startsWith('عسل ') && materialName.length > 5) {
                    const parts = materialName.split(' ', 2);
                    if (parts.length === 2) {
                        defaultHoneyVariety = parts[1].trim();
                    }
                }
            }
            
            honeySelect.dataset.defaultValue = defaultHoneyVariety;

            const honeyPlaceholder = document.createElement('option');
            honeyPlaceholder.value = '';
            honeyPlaceholder.textContent = 'اختر مورد العسل أولاً';
            honeyPlaceholder.disabled = true;
            honeyPlaceholder.selected = true;
            honeySelect.appendChild(honeyPlaceholder);

            const honeyHelper = document.createElement('small');
            honeyHelper.className = 'text-muted d-block mt-1';
            if (defaultHoneyVariety) {
                honeyHelper.textContent = isAggregatedHoneyCard
                    ? `نوع العسل المحدد مسبقاً: ${defaultHoneyVariety} - سيتم التحقق من توافره لدى المورد المختار.`
                    : `نوع العسل المحدد مسبقاً: ${defaultHoneyVariety} - سيتم التحقق من توافره لدى المورد المختار.`;
            } else {
                honeyHelper.textContent = isAggregatedHoneyCard
                    ? 'بعد اختيار مورد العسل، سيتم تطبيق نوع العسل على جميع مواد العسل.'
                    : 'بعد اختيار مورد العسل، اختر نوع العسل المتوفر لديه.';
            }

            honeyWrapper.appendChild(honeyLabel);
            honeyWrapper.appendChild(honeySelect);
            honeyWrapper.appendChild(honeyHelper);

            let syncHiddenInputs = () => {};

            if (isAggregatedHoneyCard && extraHoneyEntries.length) {
                const hiddenContainer = document.createElement('div');
                hiddenContainer.className = 'd-none aggregated-honey-hidden-inputs';

                extraHoneyEntries.forEach(entry => {
                    const hiddenSupplier = document.createElement('input');
                    hiddenSupplier.type = 'hidden';
                    hiddenSupplier.name = 'material_suppliers[' + entry.key + ']';
                    hiddenContainer.appendChild(hiddenSupplier);

                    const hiddenVariety = document.createElement('input');
                    hiddenVariety.type = 'hidden';
                    hiddenVariety.name = 'material_honey_varieties[' + entry.key + ']';
                    hiddenContainer.appendChild(hiddenVariety);
                });

                card.appendChild(hiddenContainer);

                syncHiddenInputs = () => {
                    extraHoneyEntries.forEach(entry => {
                        const hiddenSupplier = hiddenContainer.querySelector(`input[name="material_suppliers[${entry.key}]"]`);
                        if (hiddenSupplier) {
                            hiddenSupplier.value = select.value;
                        }
                        const hiddenVariety = hiddenContainer.querySelector(`input[name="material_honey_varieties[${entry.key}]"]`);
                        if (hiddenVariety) {
                            hiddenVariety.value = honeySelect.value;
                        }
                    });
                };
            }

            const updateHoneyHelperMessage = () => {
                const selectedOption = honeySelect.options[honeySelect.selectedIndex];
                if (!selectedOption || !selectedOption.value) {
                    if (!select.value || select.value === '') {
                        honeyHelper.textContent = 'يرجى اختيار مورد العسل أولاً';
                    } else {
                        honeyHelper.textContent = isAggregatedHoneyCard
                            ? 'اختر نوع العسل من القائمة أعلاه'
                            : 'اختر نوع العسل المتاح لدى المورد المختار.';
                    }
                    return;
                }
                const rawQty = parseFloat(selectedOption.dataset.raw || '0');
                const filteredQty = parseFloat(selectedOption.dataset.filtered || '0');
                const parts = [];
                if (rawQty > 0) {
                    parts.push(`خام: ${rawQty.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} كجم`);
                }
                if (filteredQty > 0) {
                    parts.push(`مصفى: ${filteredQty.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} كجم`);
                }
                honeyHelper.textContent = parts.length
                    ? `الكميات المتاحة لدى المورد: ${parts.join(' | ')}`
                    : 'لا توجد كميات مسجلة لهذا النوع لدى المورد.';
            };

            const normalizedHoneyComponent = { ...component };
            normalizedHoneyComponent.type = canonicalType || normalizedHoneyComponent.type;

            const handleSupplierChange = () => {
                populateHoneyVarietyOptions(honeySelect, select.value, normalizedHoneyComponent);
                updateHoneyHelperMessage();
                syncHiddenInputs();
            };

            select.addEventListener('change', handleSupplierChange);
            honeySelect.addEventListener('change', () => {
                updateHoneyHelperMessage();
                syncHiddenInputs();
            });

            card.appendChild(honeyWrapper);

            handleSupplierChange();
        }

        col.appendChild(card);
        container.appendChild(col);

        if (autoSelectSupplierId !== null) {
            select.value = String(autoSelectSupplierId);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    wrapper.classList.remove('d-none');

    if (hintText) {
        hintText.textContent = details.hint || 'يرجى اختيار المورد المحاسب للمادة وتحديد نوع العسل عند الحاجة.';
    }

    currentTemplateMode = 'advanced';
    modeInput.value = 'advanced';
}

// تحميل بيانات الإنتاج عند التعديل
function editProduction(id) {
    const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
    const url = baseUrl + '/dashboard/production.php?page=production&ajax=1&id=' + id;
    
    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                document.getElementById('edit_production_id').value = data.production.id;
                document.getElementById('edit_product_id').value = data.production.product_id;
                document.getElementById('edit_quantity').value = data.production.quantity;
                document.getElementById('edit_production_date').value = data.production.date;
                document.getElementById('edit_materials_used').value = data.production.materials_used || '';
                document.getElementById('edit_notes').value = data.production.notes || '';
                if (document.getElementById('edit_status')) {
                    document.getElementById('edit_status').value = data.production.status || 'pending';
                }
                
                const modal = new bootstrap.Modal(document.getElementById('editProductionModal'));
                modal.show();
            } else {
                alert(data.message || 'حدث خطأ في تحميل البيانات');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('حدث خطأ في تحميل البيانات: ' + error.message);
        });
}

// حذف سجل إنتاج
function deleteProduction(id) {
    if (confirm('<?php echo isset($lang['confirm_delete']) ? $lang['confirm_delete'] : 'هل أنت متأكد من حذف هذا السجل؟'; ?>')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_production">
            <input type="hidden" name="production_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// عرض تفاصيل الإنتاج
function viewProduction(id) {
    // TODO: إضافة نافذة لعرض التفاصيل كاملة
    alert('عرض تفاصيل الإنتاج #' + id);
}

// فتح نافذة إنشاء إنتاج من قالب
function openCreateFromTemplateModal(element) {
    const templateId = element.getAttribute('data-template-id');
    const templateName = element.getAttribute('data-template-name');
    const templateType = element.getAttribute('data-template-type') || 'legacy';
    
    try {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (scrollError) {
        window.scrollTo(0, 0);
    }
    
    document.querySelectorAll('.template-card-modern.selected-template').forEach(card => {
        card.classList.remove('selected-template');
        card.style.setProperty('--template-accent', card.dataset.originalAccent || '#0ea5e9');
        card.style.setProperty('--template-accent-light', card.dataset.originalAccentLight || 'rgba(14, 165, 233, 0.15)');
    });
    if (element) {
        element.classList.add('selected-template');
        element.dataset.originalAccent = getComputedStyle(element).getPropertyValue('--template-accent');
        element.dataset.originalAccentLight = getComputedStyle(element).getPropertyValue('--template-accent-light');
        element.style.setProperty('--template-accent', '#1d4ed8');
        element.style.setProperty('--template-accent-light', '#1d4ed822');
    }
    
    document.getElementById('template_id').value = templateId;
    document.getElementById('template_product_name').value = templateName;
    document.getElementById('template_type').value = templateType;
    
    const extraSuppliersSelect = document.getElementById('extraSuppliersSelect');
    if (extraSuppliersSelect) {
        Array.from(extraSuppliersSelect.options).forEach(option => {
            option.selected = false;
        });
    }
    const materialsInfoBox = document.getElementById('templateMaterialsInfo');
    if (materialsInfoBox) {
        materialsInfoBox.innerHTML = '<div class="text-muted small">سيتم عرض المواد والمقادير الخاصة بالقالب بعد اختيار قالب الإنتاج.</div>';
    }

    const wrapper = document.getElementById('templateSuppliersWrapper');
    const container = document.getElementById('templateSuppliersContainer');
    const modeInput = document.getElementById('template_mode');
    const summaryWrapper = document.getElementById('templateComponentsSummary');
    const summaryGrid = document.getElementById('templateComponentsSummaryGrid');

    if (container) {
        container.innerHTML = `
            <div class="col-12">
                <div class="alert alert-info mb-0">
                    <i class="bi bi-hourglass-split me-2"></i>
                    جاري تحميل بيانات المواد...
                </div>
            </div>
        `;
    }
    if (wrapper) {
        wrapper.classList.add('d-none');
    }
    if (summaryGrid) {
        summaryGrid.innerHTML = '';
    }
    if (summaryWrapper) {
        summaryWrapper.classList.add('d-none');
    }
    currentTemplateMode = 'advanced';
    if (modeInput) {
        modeInput.value = 'advanced';
    }

    const templateCacheKey = templateId + '::' + templateType;
    window.templateDetailsCache = window.templateDetailsCache || {};

    const modalElement = document.getElementById('createFromTemplateModal');
    if (!modalElement) {
        console.error('createFromTemplateModal element not found in DOM.');
        return;
    }
    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    const showTemplateMessage = (html, wrapperClass = '') => {
        const materialsInfoBox = document.getElementById('templateMaterialsInfo');
        if (materialsInfoBox) {
            materialsInfoBox.innerHTML = html;
        }
        if (wrapper) {
            wrapper.classList.remove('d-none');
            if (wrapperClass) {
                wrapper.className = wrapper.className.replace(/\b(alert-\w+)\b/g, '').trim();
                wrapper.classList.add(wrapperClass);
            }
        }
        if (container) {
            container.innerHTML = '';
        }
    };

    const handleTemplateResponse = (data) => {
        if (data && data.success) {
            renderTemplateSuppliers(data);
        } else {
            showTemplateMessage(
                '<div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-2"></i>لم يتم العثور على مواد لهذا القالب. يرجى مراجعة إعدادات القالب.</div>'
            );
        }
    };

    if (window.templateDetailsCache[templateCacheKey]) {
        handleTemplateResponse(window.templateDetailsCache[templateCacheKey]);
        return;
    }

    const requestUrl = TEMPLATE_DETAILS_BASE_URL +
        '?page=production&ajax=template_details&template_id=' + encodeURIComponent(templateId) +
        '&template_type=' + encodeURIComponent(templateType);

    fetch(requestUrl, { cache: 'no-store' })
        .then(response => response.ok ? response.json() : Promise.reject(new Error('Network error')))
        .then(data => {
            if (data && data.success) {
                const cacheKey = data.cache_key || templateCacheKey;
                window.templateDetailsCache[cacheKey] = data;
                window.templateDetailsCache[templateCacheKey] = data;
            }
            handleTemplateResponse(data);
        })
        .catch(error => {
            console.error('Error loading template details:', error);
            showTemplateMessage(
                `<div class="alert alert-danger mb-0"><i class="bi bi-x-circle me-2"></i>تعذّر تحميل بيانات القالب: ${error.message}</div>`
            );
        });
}

// إضافة معالجة للنماذج للتحقق من الحقول المطلوبة
document.getElementById('createFromTemplateForm')?.addEventListener('submit', function(e) {
    const quantity = document.querySelector('input[name="quantity"]').value;

    const supplierSelects = document.querySelectorAll('#templateSuppliersContainer select[data-role="component-supplier"]');

    if (supplierSelects.length === 0) {
            e.preventDefault();
        alert('لا توجد مواد مرتبطة بالقالب، يرجى مراجعة القالب قبل إنشاء التشغيلة.');
            return false;
        }

        for (let select of supplierSelects) {
            if (!select.value) {
                e.preventDefault();
                alert('يرجى اختيار المورد المناسب لهذه المادة للمتابعة');
                select.focus();
                return false;
            }
    }

    const honeyVarietyFields = document.querySelectorAll('#templateSuppliersContainer [data-role="honey-variety"]');
    for (let field of honeyVarietyFields) {
        if (field.disabled) {
            e.preventDefault();
            alert('يرجى اختيار مورد العسل بعد تحديد النوع');
            field.focus();
            return false;
        }
        if (!field.value || !field.value.trim()) {
            e.preventDefault();
            alert('يرجى إدخال نوع العسل لدى المورد المختار');
            field.focus();
            return false;
        }
    }

    // التحقق من الكمية
    if (!quantity || parseInt(quantity) <= 0) {
        e.preventDefault();
        alert('يرجى إدخال كمية صحيحة أكبر من الصفر');
        document.querySelector('input[name="quantity"]').focus();
        return false;
    }
});

document.getElementById('createFromTemplateModal')?.addEventListener('shown.bs.modal', function() {
    const modalBody = document.querySelector('#createFromTemplateModal .production-template-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
        modalBody.style.overflowY = 'auto';
    }
});

document.getElementById('createFromTemplateModal')?.addEventListener('hidden.bs.modal', function() {
    const modalBody = document.querySelector('#createFromTemplateModal .production-template-body');
    if (modalBody) {
        modalBody.scrollTop = 0;
        modalBody.style.overflowY = '';
    }
});

document.getElementById('printBarcodesModal')?.addEventListener('hidden.bs.modal', function() {
    const fallbackMessages = document.getElementById('barcodeFallbackMessages');
    if (fallbackMessages) {
        fallbackMessages.classList.add('d-none');
        fallbackMessages.innerHTML = '';
    }

    const directPrintButton = document.getElementById('openDirectPrintBtn');
    if (directPrintButton) {
        directPrintButton.classList.add('d-none');
        directPrintButton.dataset.printUrl = '';
        directPrintButton.disabled = false;
        directPrintButton.onclick = null;
    }
});

// طباعة الباركودات
function printBarcodes() {
    const actionButton = document.getElementById('sendBarcodeToTelegramBtn');
    const fallbackMessages = document.getElementById('barcodeFallbackMessages');
    const directPrintButton = document.getElementById('openDirectPrintBtn');

    if (fallbackMessages) {
        fallbackMessages.classList.add('d-none');
        fallbackMessages.innerHTML = '';
    }

    if (directPrintButton) {
        directPrintButton.classList.add('d-none');
        directPrintButton.dataset.printUrl = '';
        directPrintButton.disabled = false;
        directPrintButton.onclick = null;
    }

    const setLoadingState = (isLoading) => {
        if (!actionButton) {
            return;
        }
        if (isLoading) {
            if (!actionButton.dataset.originalContent) {
                actionButton.dataset.originalContent = actionButton.innerHTML;
            }
            actionButton.disabled = true;
            actionButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>جاري الإرسال...';
        } else {
            const original = actionButton.dataset.originalContent;
            if (original) {
                actionButton.innerHTML = original;
            }
            actionButton.disabled = false;
        }
    };

    const batchNumbers = window.batchNumbersToPrint || [];
    if (batchNumbers.length === 0) {
        alert('لا توجد أرقام تشغيل للطباعة');
        return;
    }

    const quantityInput = document.getElementById('barcode_print_quantity');
    const printQuantity = parseInt(quantityInput && quantityInput.value ? quantityInput.value : '1', 10) || 1;
    const batchNumber = batchNumbers[0];
    const printUrl = `${PRINT_BARCODE_URL}?batch=${encodeURIComponent(batchNumber)}&quantity=${printQuantity}&print=1`;

    const batchInfo = (typeof window.batchPrintInfo === 'object' && window.batchPrintInfo !== null)
        ? window.batchPrintInfo
        : {};
    const telegramEnabled = !!(batchInfo.telegram_enabled);
    const contextToken = batchInfo.context_token || '';
    const hasEndpoint = typeof SEND_BARCODE_TELEGRAM_URL === 'string' && SEND_BARCODE_TELEGRAM_URL !== '';
    let fallbackTriggered = false;

    const fallbackToDirectPrint = (message, options = {}) => {
        if (fallbackTriggered) {
            return;
        }
        fallbackTriggered = true;

        const { autoOpen = true } = options;
        const finalMessage = message || 'تعذر إرسال الرابط إلى تليجرام، يمكنك استخدام الرابط التالي للطباعة اليدوية.';

        const buildFallbackMessage = () => {
            if (!fallbackMessages) {
                if (finalMessage) {
                    alert(finalMessage);
                }
                return;
            }

            const copyToClipboard = (text, onSuccess, onFailure) => {
                const fallbackCopy = () => {
                    const tempTextarea = document.createElement('textarea');
                    tempTextarea.value = text;
                    tempTextarea.style.position = 'fixed';
                    tempTextarea.style.opacity = '0';
                    tempTextarea.style.pointerEvents = 'none';
                    document.body.appendChild(tempTextarea);
                    tempTextarea.focus();
                    tempTextarea.select();
                    let successful = false;
                    try {
                        successful = document.execCommand('copy');
                    } catch (err) {
                        successful = false;
                    }
                    document.body.removeChild(tempTextarea);
                    if (successful) {
                        onSuccess();
                    } else {
                        onFailure();
                    }
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text)
                        .then(onSuccess)
                        .catch(() => fallbackCopy());
                } else {
                    fallbackCopy();
                }
            };

            fallbackMessages.classList.remove('d-none');
            fallbackMessages.innerHTML = '';

            const alertWrapper = document.createElement('div');
            alertWrapper.className = 'alert alert-warning d-flex align-items-start gap-3 mb-0';

            const icon = document.createElement('i');
            icon.className = 'bi bi-exclamation-triangle-fill fs-4 text-warning';

            const content = document.createElement('div');

            const title = document.createElement('div');
            title.className = 'fw-semibold mb-1';
            title.textContent = finalMessage;

            const helper = document.createElement('p');
            helper.className = 'mb-2 small text-muted';
            helper.textContent = 'يمكنك فتح صفحة الطباعة أو نسخ الرابط وإرساله يدوياً.';

            const inputGroup = document.createElement('div');
            inputGroup.className = 'input-group input-group-sm';

            const urlInput = document.createElement('input');
            urlInput.type = 'text';
            urlInput.className = 'form-control';
            urlInput.value = printUrl;
            urlInput.readOnly = true;
            urlInput.setAttribute('aria-label', 'رابط الطباعة');

            const copyButton = document.createElement('button');
            copyButton.type = 'button';
            copyButton.className = 'btn btn-outline-primary';
            copyButton.textContent = 'نسخ الرابط';

            const resetCopyButton = (text = 'نسخ الرابط') => {
                setTimeout(() => {
                    copyButton.textContent = text;
                }, 2000);
            };

            copyButton.addEventListener('click', () => {
                copyButton.disabled = true;
                copyToClipboard(
                    printUrl,
                    () => {
                        copyButton.textContent = 'تم النسخ!';
                        copyButton.disabled = false;
                        resetCopyButton();
                    },
                    () => {
                        copyButton.textContent = 'تعذّر النسخ';
                        copyButton.disabled = false;
                        resetCopyButton();
                    }
                );
            });

            inputGroup.append(urlInput, copyButton);

            content.append(title, helper, inputGroup);
            alertWrapper.append(icon, content);
            fallbackMessages.append(alertWrapper);
        };

        buildFallbackMessage();

        if (directPrintButton) {
            directPrintButton.classList.remove('d-none');
            directPrintButton.dataset.printUrl = printUrl;
            directPrintButton.onclick = () => {
                const targetUrl = directPrintButton.dataset.printUrl;
                if (!targetUrl) {
                    return;
                }
                openUrlInAppModalOrWindow(targetUrl, { opener: directPrintButton });
            };
        }

        if (autoOpen) {
            openUrlInAppModalOrWindow(printUrl, { opener: document.activeElement instanceof Element ? document.activeElement : null });
        }
    };

    if (!telegramEnabled || !contextToken || !hasEndpoint) {
        setLoadingState(false);
        fallbackToDirectPrint('لا يمكن إرسال رابط الطباعة عبر تليجرام حالياً. استخدم الرابط أدناه للطباعة اليدوية.', { autoOpen: false });
        return;
    }

    setLoadingState(true);

    const payload = {
        batch_number: batchNumber,
        labels: printQuantity,
        context_token: contextToken,
        product_name: batchInfo.product_name || '',
        production_id: batchInfo.production_id || null,
        production_date: batchInfo.production_date || null,
        quantity: batchInfo.quantity || null,
        unit: batchInfo.unit || null,
        metadata: {
            workers: Array.isArray(batchInfo.workers) ? batchInfo.workers : [],
            honey_supplier_name: batchInfo.honey_supplier_name || null,
            packaging_supplier_name: batchInfo.packaging_supplier_name || null,
            extra_suppliers: Array.isArray(batchInfo.extra_suppliers) ? batchInfo.extra_suppliers : [],
            raw_materials: Array.isArray(batchInfo.raw_materials) ? batchInfo.raw_materials : [],
            packaging_materials: Array.isArray(batchInfo.packaging_materials) ? batchInfo.packaging_materials : [],
            notes: batchInfo.notes || null,
            template_id: batchInfo.template_id || null,
            quantity_unit_label: batchInfo.quantity_unit_label || null,
        },
    };

    fetch(SEND_BARCODE_TELEGRAM_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
    })
        .then((response) => response.json().catch(() => ({})))
        .then((data) => {
            if (!data || data.success !== true) {
                console.warn('فشل إرسال إشعار الطباعة إلى تليجرام', data && data.error ? data.error : data);
                setLoadingState(false);
                fallbackToDirectPrint('تعذر إرسال الرابط إلى تليجرام، سيتم فتح صفحة الطباعة الآن.');
                return;
            }
            batchInfo.telegram_last_sent_at = Date.now();
            batchInfo.telegram_last_response = data;
            setLoadingState(false);
            alert('تم إرسال رابط الطباعة إلى تليجرام بنجاح. يمكن للمدير فتح الرابط والبدء بالطباعة.');
            const modalElement = document.getElementById('printBarcodesModal');
            if (modalElement && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modalInstance = bootstrap.Modal.getInstance(modalElement);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
        })
        .catch((error) => {
            console.error('خطأ أثناء إرسال إشعار الطباعة إلى تليجرام', error);
            setLoadingState(false);
            fallbackToDirectPrint('حدث خطأ أثناء محاولة الإرسال إلى تليجرام، سيتم فتح صفحة الطباعة الآن.');
        });
}

// إدارة المواد الخام في نافذة إنشاء القالب
let rawMaterialIndex = 0;

function addRawMaterial() {
    const container = document.getElementById('rawMaterialsContainer');
    const materialHtml = `
        <div class="raw-material-item mb-2 border p-2 rounded">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label small">اسم المادة <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][name]" 
                           placeholder="مثلاً: مكسرات أو عطر" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">الكمية <span class="text-danger">*</span></label>
                    <input type="number" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][quantity]" 
                           step="0.001" min="0.001" placeholder="0.000" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">الوحدة</label>
                    <input type="text" class="form-control form-control-sm" name="raw_materials[${rawMaterialIndex}][unit]" 
                           value="جرام" placeholder="جرام">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger w-100" onclick="removeRawMaterial(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', materialHtml);
    rawMaterialIndex++;
}

function removeRawMaterial(btn) {
    btn.closest('.raw-material-item').remove();
}

// التحقق من صحة النموذج قبل الإرسال
document.getElementById('createTemplateForm')?.addEventListener('submit', function(e) {
    // التحقق من اختيار أداة تعبئة واحدة على الأقل
    const packagingCheckboxes = document.querySelectorAll('input[name="packaging_ids[]"]:checked');
    if (packagingCheckboxes.length === 0) {
        e.preventDefault();
        alert('يرجى اختيار أداة تعبئة واحدة على الأقل');
        const firstCheckbox = document.querySelector('input[name="packaging_ids[]"]');
        if (firstCheckbox) {
            firstCheckbox.focus();
        }
        return false;
    }
    
    // التحقق من صحة كمية العسل
    const honeyQuantity = parseFloat(document.querySelector('input[name="honey_quantity"]').value);
    if (!honeyQuantity || honeyQuantity <= 0) {
        e.preventDefault();
        alert('يرجى إدخال كمية عسل صحيحة أكبر من الصفر');
        return false;
    }
    
    // التحقق من إدخال اسم المنتج
    const productName = document.querySelector('input[name="product_name"]').value.trim();
    if (!productName) {
        e.preventDefault();
        alert('يرجى إدخال اسم المنتج');
        return false;
    }
});

<?php
// عرض نموذج الطباعة إذا تم إنشاء إنتاج من قالب
if (isset($_GET['show_barcode_modal']) && isset($_SESSION['created_batch_numbers'])) {
    $batchNumbers = $_SESSION['created_batch_numbers'];
    $productName = $_SESSION['created_batch_product_name'] ?? '';
    $quantity = $_SESSION['created_batch_quantity'] ?? count($batchNumbers);
    $batchMetadata = $_SESSION['created_batch_metadata'] ?? null;
    $contextTokenValue = $_SESSION['created_batch_context_token'] ?? '';

    if (is_array($batchMetadata) && $contextTokenValue !== '' && empty($batchMetadata['context_token'])) {
        $batchMetadata['context_token'] = $contextTokenValue;
    }

    // تنظيف بيانات الجلسة للقيم المؤقتة
    unset($_SESSION['created_batch_numbers']);
    unset($_SESSION['created_batch_product_name']);
    unset($_SESSION['created_batch_quantity']);

    $batchNumbersJson = json_encode(array_values($batchNumbers), JSON_UNESCAPED_UNICODE);
    if ($batchNumbersJson === false) {
        $batchNumbersJson = '[]';
    }

    $productNameJson = json_encode($productName, JSON_UNESCAPED_UNICODE);
    if ($productNameJson === false) {
        $productNameJson = '""';
    }

    $firstBatchNumberJson = json_encode($batchNumbers[0] ?? '', JSON_UNESCAPED_UNICODE);
    if ($firstBatchNumberJson === false) {
        $firstBatchNumberJson = '""';
    }

    $quantityValue = (int) $quantity;
    $batchMetadataJson = json_encode($batchMetadata, JSON_UNESCAPED_UNICODE);
    if ($batchMetadataJson === false) {
        $batchMetadataJson = 'null';
    }
    $contextTokenJs = json_encode($contextTokenValue, JSON_UNESCAPED_UNICODE);
    if ($contextTokenJs === false) {
        $contextTokenJs = 'null';
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const batchNumbers = <?= $batchNumbersJson ?>;
        const firstBatchNumber = <?= $firstBatchNumberJson ?>;
        const productName = <?= $productNameJson ?>;
        const barcodeQuantity = <?= $quantityValue ?>;
        const batchMeta = <?= $batchMetadataJson ?>;
        const contextToken = <?= $contextTokenJs ?>;
        const telegramEnabled = <?= isTelegramConfigured() ? 'true' : 'false'; ?>;

        window.batchNumbersToPrint = Array.isArray(batchNumbers) ? batchNumbers : [];
        if (batchMeta && typeof batchMeta === 'object') {
            batchMeta.telegram_enabled = telegramEnabled;
            if (contextToken && !batchMeta.context_token) {
                batchMeta.context_token = contextToken;
            }
            window.batchPrintInfo = batchMeta;
        } else {
            window.batchPrintInfo = {
                telegram_enabled: telegramEnabled,
                context_token: contextToken || ''
            };
        }

        const productNameInput = document.getElementById('barcode_product_name');
        const quantityText = document.getElementById('barcode_quantity');
        const quantityInput = document.getElementById('barcode_print_quantity');
        const batchListContainer = document.getElementById('batch_numbers_list');
        const modalElement = document.getElementById('printBarcodesModal');

        if (productNameInput) {
            productNameInput.value = productName;
        }

        if (quantityText) {
            quantityText.textContent = barcodeQuantity;
        }

        if (quantityInput) {
            quantityInput.value = barcodeQuantity;
        }

        if (batchListContainer) {
            let batchListHtml = '<div class="alert alert-info mb-0">';
            batchListHtml += '<i class="bi bi-info-circle me-2"></i>';
            batchListHtml += '<strong>رقم التشغيلة:</strong> ' + (firstBatchNumber || '') + '<br>';
            batchListHtml += '<small>ستتم طباعة نفس رقم التشغيلة بعدد ' + barcodeQuantity + ' باركود</small>';
            batchListHtml += '</div>';
            batchListContainer.innerHTML = batchListHtml;
        }

        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
    });
    </script>
    <?php
}
?>
</script>

<?php
// معالجة طلبات AJAX الخاصة بتحميل بيانات الإنتاج
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['id'])) {
    $productionId = intval($_GET['id']);
    $production = $db->queryOne(
        "SELECT p.*, pr.name as product_name FROM production p 
         LEFT JOIN products pr ON p.product_id = pr.id 
         WHERE p.id = ?",
        [$productionId]
    );
    
    if ($production) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'production' => [
                'id' => $production['id'],
                'product_id' => $production['product_id'],
                'quantity' => $production['quantity'],
                'date' => $production[$dateColumn] ?? $production['created_at'],
                'materials_used' => $production['materials_used'] ?? '',
                'notes' => $production['notes'] ?? '',
                'status' => $production['status'] ?? 'pending'
            ]
        ]);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'لم يتم العثور على السجل']);
        exit;
    }
}
?>

<script>
// منع تكرار الإرسال - إضافة رمز فريد لكل نموذج
document.addEventListener('DOMContentLoaded', function() {
    // البحث عن جميع النماذج في الصفحة
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    
    forms.forEach(function(form) {
        // التحقق من وجود حقل الرمز مسبقًا
        if (!form.querySelector('input[name="submit_token"]')) {
            // إنشاء رمز فريد
            const token = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            // إنشاء حقل مخفي للاحتفاظ بالرمز
            const tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'submit_token';
            tokenInput.value = token;
            
            // إلحاق الرمز بالنموذج
            form.appendChild(tokenInput);
        }
        
        // منع إعادة الإرسال عند الضغط على زر الإرسال
        form.addEventListener('submit', function(e) {
            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton) {
                // تعطيل الزر مؤقتًا
                submitButton.disabled = true;
                submitButton.style.opacity = '0.6';
                submitButton.style.cursor = 'not-allowed';
                
                // إضافة نص "جاري المعالجة..."
                const originalText = submitButton.innerHTML || submitButton.value;
                if (submitButton.tagName === 'BUTTON') {
                    submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> جاري المعالجة...';
                } else {
                    submitButton.value = 'جاري المعالجة...';
                }
                
                // إعادة تفعيل الزر بعد 3 ثوانٍ (في حال فشل الإرسال)
                setTimeout(function() {
                    submitButton.disabled = false;
                    submitButton.style.opacity = '1';
                    submitButton.style.cursor = 'pointer';
                    if (submitButton.tagName === 'BUTTON') {
                        submitButton.innerHTML = originalText;
                    } else {
                        submitButton.value = originalText;
                    }
                    
                    // تحديث الرمز
                    const newToken = Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    const tokenInput = form.querySelector('input[name="submit_token"]');
                    if (tokenInput) {
                        tokenInput.value = newToken;
                    }
                }, 3000);
            }
        });
    });
    
    // منع إعادة الإرسال عند تحديث الصفحة (F5 أو Refresh)
    if (performance.navigation.type === 1) {
        // الصفحة تم تحديثها (Refresh)
        // إزالة أي رسائل خطأ قد تنتج عن إعادة الإرسال
        console.log('تم اكتشاف إعادة تحميل الصفحة - تم منع إعادة الإرسال');
    }
});

// تحذير عند محاولة مغادرة الصفحة مع وجود تغييرات
window.addEventListener('beforeunload', function(e) {
    const forms = document.querySelectorAll('form[method="post"], form[method="POST"]');
    let formModified = false;
    
    forms.forEach(function(form) {
        // التحقق إذا تم تعديل أي حقل
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(function(input) {
            if (input.value !== input.defaultValue) {
                formModified = true;
            }
        });
    });
    
    // لا تعرض تحذيرًا إذا لم يتم تعديل أي شيء
    if (!formModified) {
        return undefined;
    }
});
</script>