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
require_once __DIR__ . '/../../includes/honey_varieties.php';

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
function checkMaterialsAvailability($db, $templateId, $productionQuantity, array $materialSuppliers = [], ?int $honeySupplierId = null) {
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

        // التحقق من توفر الكمية المطلوبة حتى عند اختيار مورد
        if ($bestAvailability !== null && $bestAvailability['available'] >= $requiredQuantity) {
            continue;
        }

        // إذا كان المورد محدداً ولكن الكمية غير كافية، يجب التحقق من ذلك
        if ($hasSupplierSelection) {
            // التحقق من الكمية المتاحة من المورد المحدد
            $selectedSupplierId = null;
            foreach ($supplierKeys as $supplierKey) {
                if (isset($materialSuppliers[$supplierKey]) && intval($materialSuppliers[$supplierKey]) > 0) {
                    $selectedSupplierId = intval($materialSuppliers[$supplierKey]);
                    break;
                }
            }
            
            // إذا كان المورد محدداً ولكن الكمية غير كافية، أضفه إلى القائمة
            if ($selectedSupplierId && $availabilityChecked) {
                if ($bestAvailability === null || $bestAvailability['available'] < $requiredQuantity) {
                    $insufficientMaterials[] = [
                        'name' => $bestAvailability['name'] ?? $packagingName,
                        'required' => $requiredQuantity,
                        'available' => max(0, $bestAvailability['available'] ?? 0),
                        'type' => 'مواد التعبئة',
                        'unit' => 'قطعة'
                    ];
                }
            } else if (!$availabilityChecked) {
                // إذا لم يتم العثور على المادة حتى مع المورد المحدد
                $missingMaterials[] = [
                    'name' => $packagingName !== '' ? $packagingName : 'مادة تعبئة غير معروفة',
                    'type' => 'مواد التعبئة'
                ];
            }
            continue;
        }

        // إذا لم يكن هناك مورد محدد، التحقق من الكمية المتاحة
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

        // إذا كان النوع محدد ومعروف، استخدمه مباشرة
        if (in_array($normalizedType, ['honey_raw', 'honey_filtered', 'honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts', 'tahini'], true)) {
            return $normalizedType;
        }

        // إذا كان النوع غير محدد أو غير معروف (مثل "ingredient", "raw_general", إلخ)،
        // حاول اكتشاف النوع من اسم المادة
        $nameNormalized = mb_strtolower(trim($materialName), 'UTF-8');

        if ($nameNormalized !== '') {
            if (mb_strpos($nameNormalized, 'زيت زيتون') !== false || mb_strpos($nameNormalized, 'olive oil') !== false) {
                return 'olive_oil';
            }
            if (mb_strpos($nameNormalized, 'عسل') !== false || mb_strpos($nameNormalized, 'honey') !== false) {
                // محاولة تحديد نوع العسل (خام أو مصفى) من الاسم
                if (mb_strpos($nameNormalized, 'خام') !== false || mb_strpos($nameNormalized, 'raw') !== false) {
                    return 'honey_raw';
                }
                if (mb_strpos($nameNormalized, 'مصفى') !== false || mb_strpos($nameNormalized, 'filtered') !== false) {
                    return 'honey_filtered';
                }
                // افتراض أن العسل المصفى هو الافتراضي للإنتاج
                return 'honey_filtered';
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
            if (mb_strpos($nameNormalized, 'طحينة') !== false || mb_strpos($nameNormalized, 'tahini') !== false) {
                return 'tahini';
            }
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

    $checkStock = static function (string $table, string $column, ?int $supplierId = null, ?string $honeyVariety = null, bool $flexibleSearch = false) use ($db): float {
        $sql = "SELECT SUM({$column}) AS total_quantity FROM {$table}";
        $params = [];
        $conditions = [];
        
        if ($supplierId) {
            $conditions[] = "supplier_id = ?";
            $params[] = $supplierId;
        }
        
        // إذا كان الجدول هو honey_stock ونوع العسل محدد، أضفه إلى الشروط
        if ($table === 'honey_stock' && $honeyVariety !== null && $honeyVariety !== '') {
            $varietyTrimmed = trim($honeyVariety);
            
            // تنظيف نوع العسل من أي رموز أو أرقام إضافية للبحث
            // إزالة الأقواس والمحتوى داخلها (مثل "سدر (KI002)" -> "سدر")
            $varietyCleaned = preg_replace('/\s*\([^)]*\)\s*/', '', $varietyTrimmed);
            $varietyCleaned = trim($varietyCleaned);
            
            // إزالة أي رموز أو أرقام في نهاية الاسم
            $varietyCleaned = preg_replace('/[\s\-_]*[A-Z0-9]+[\s\-_]*$/', '', $varietyCleaned);
            $varietyCleaned = trim($varietyCleaned);
            
            // إذا كان التنظيف أنتج اسمًا فارغًا، استخدم الاسم الأصلي
            if (empty($varietyCleaned)) {
                $varietyCleaned = $varietyTrimmed;
            }
            
            // الحصول على قائمة أنواع العسل الصحيحة من ENUM
            // القيم الصحيحة في ENUM: 'سدر','جبلي','حبة البركة','موالح','نوارة برسيم','أخرى'
            $validHoneyVarieties = ['سدر', 'جبلي', 'حبة البركة', 'موالح', 'نوارة برسيم', 'أخرى'];
            
            // محاولة مطابقة نوع العسل مع القيم الصحيحة في ENUM
            $matchedVarieties = [];
            
            // 1. المطابقة الدقيقة للاسم الأصلي
            if (in_array($varietyTrimmed, $validHoneyVarieties, true)) {
                $matchedVarieties[] = $varietyTrimmed;
            }
            
            // 2. المطابقة الدقيقة للاسم المطهر
            if ($varietyCleaned !== $varietyTrimmed && in_array($varietyCleaned, $validHoneyVarieties, true)) {
                $matchedVarieties[] = $varietyCleaned;
            }
            
            // 3. إذا لم يتم العثور على مطابقة دقيقة، جرب البحث المرن في القائمة
            if (empty($matchedVarieties) && $flexibleSearch) {
                foreach ($validHoneyVarieties as $validVariety) {
                    // البحث عن نوع العسل المستخرج داخل القيم الصحيحة
                    if (mb_stripos($validVariety, $varietyCleaned) !== false || mb_stripos($varietyCleaned, $validVariety) !== false) {
                        $matchedVarieties[] = $validVariety;
                    }
                }
            }
            
            // 4. إذا لم يتم العثور على أي مطابقة، استخدم القيم الأصلية للبحث
            if (empty($matchedVarieties)) {
                $matchedVarieties = [$varietyTrimmed, $varietyCleaned];
            }
            
            // إزالة التكرارات
            $matchedVarieties = array_unique($matchedVarieties);
            
            // بناء شرط البحث - استخدام المطابقة الدقيقة (=) مع ENUM
            if (count($matchedVarieties) === 1) {
                $conditions[] = "honey_variety = ?";
                $params[] = $matchedVarieties[0];
            } elseif (count($matchedVarieties) > 1) {
                $placeholders = implode(',', array_fill(0, count($matchedVarieties), '?'));
                $conditions[] = "honey_variety IN ({$placeholders})";
                $params = array_merge($params, $matchedVarieties);
            }
        }
        
        // إضافة شرط أن الكمية أكبر من 0
        $conditions[] = "{$column} > 0";
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $stockRow = $db->queryOne($sql, $params);
        $quantity = (float)($stockRow['total_quantity'] ?? 0);
        
        // إذا كانت المطابقة الدقيقة فشلت وكان نوع العسل محدد، جرب البحث المرن
        if ($quantity == 0 && $table === 'honey_stock' && $honeyVariety !== null && $honeyVariety !== '' && !$flexibleSearch) {
            return $checkStock($table, $column, $supplierId, $honeyVariety, true);
        }
        
        return $quantity;
    };

    $resolveSpecialStock = static function (?string $materialType, ?int $supplierId, string $materialName, ?string $honeyVariety = null) use ($db, $checkStock, $normalizeMaterialType, $normalizeUnitLabel): array {
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
                    
                    // البحث مع المورد المحدد ونوع العسل المحدد أولاً (بالبحث المرن مباشرة)
                    if ($supplierId && $honeyVariety) {
                        // جرب البحث المرن أولاً إذا كان نوع العسل محددًا
                        $availableQuantity = $checkStock('honey_stock', $column, $supplierId, $honeyVariety, true);
                    }
                    // إذا لم يتم العثور على شيء، جرب البحث مع المورد فقط (جميع أنواع العسل)
                    if ($availableQuantity == 0 && $supplierId) {
                        $availableQuantity = $checkStock('honey_stock', $column, $supplierId, null);
                    }
                    // إذا لم يتم العثور على شيء، جرب البحث بنوع العسل فقط (جميع الموردين) - مع بحث مرن
                    if ($availableQuantity == 0 && $honeyVariety) {
                        // استخدام البحث المرن مباشرة
                        $availableQuantity = $checkStock('honey_stock', $column, null, $honeyVariety, true);
                        
                        // إذا لم يجد، جرب البحث الدقيق أيضاً
                        if ($availableQuantity == 0) {
                            $availableQuantity = $checkStock('honey_stock', $column, null, $honeyVariety, false);
                        }
                    }
                    // إذا لم يتم العثور على شيء، جرب البحث بدون أي قيود
                    if ($availableQuantity == 0) {
                        $availableQuantity = $checkStock('honey_stock', $column, null, null);
                    }
                    
                    // إذا لم يتم العثور على شيء بعد كل المحاولات ونوع العسل محدد، جرب بحث إضافي مباشر في قاعدة البيانات
                    if ($availableQuantity == 0 && $honeyVariety) {
                        // تنظيف نوع العسل من أي رموز أو أرقام إضافية
                        $varietyTrimmed = trim($honeyVariety);
                        $varietyCleaned = preg_replace('/\s*\([^)]*\)\s*/', '', $varietyTrimmed);
                        $varietyCleaned = trim(preg_replace('/[\s\-_]*[A-Z0-9]+[\s\-_]*$/', '', $varietyCleaned));
                        if (empty($varietyCleaned)) {
                            $varietyCleaned = $varietyTrimmed;
                        }
                        
                        // الحصول على قائمة أنواع العسل الصحيحة من ENUM
                        $validHoneyVarieties = ['سدر', 'جبلي', 'حبة البركة', 'موالح', 'نوارة برسيم', 'أخرى'];
                        
                        // محاولة مطابقة نوع العسل مع القيم الصحيحة في ENUM
                        $matchedVarieties = [];
                        
                        // المطابقة الدقيقة
                        if (in_array($varietyTrimmed, $validHoneyVarieties, true)) {
                            $matchedVarieties[] = $varietyTrimmed;
                        }
                        if ($varietyCleaned !== $varietyTrimmed && in_array($varietyCleaned, $validHoneyVarieties, true)) {
                            $matchedVarieties[] = $varietyCleaned;
                        }
                        
                        // البحث المرن في القائمة
                        if (empty($matchedVarieties)) {
                            foreach ($validHoneyVarieties as $validVariety) {
                                if (mb_stripos($validVariety, $varietyCleaned) !== false || mb_stripos($varietyCleaned, $validVariety) !== false) {
                                    $matchedVarieties[] = $validVariety;
                                }
                            }
                        }
                        
                        // إذا لم يتم العثور على أي مطابقة، استخدم القيم الأصلية
                        if (empty($matchedVarieties)) {
                            $matchedVarieties = [$varietyTrimmed, $varietyCleaned];
                        }
                        
                        $matchedVarieties = array_unique($matchedVarieties);
                        
                        // البحث المباشر في قاعدة البيانات باستخدام المطابقة الدقيقة مع ENUM
                        if (!empty($matchedVarieties)) {
                            $directSearchSql = "SELECT SUM({$column}) AS total_quantity FROM honey_stock WHERE {$column} > 0";
                            $directSearchParams = [];
                            
                            if (count($matchedVarieties) === 1) {
                                $directSearchSql .= " AND honey_variety = ?";
                                $directSearchParams[] = $matchedVarieties[0];
                            } elseif (count($matchedVarieties) > 1) {
                                $placeholders = implode(',', array_fill(0, count($matchedVarieties), '?'));
                                $directSearchSql .= " AND honey_variety IN ({$placeholders})";
                                $directSearchParams = array_merge($directSearchParams, $matchedVarieties);
                            }
                            
                            if ($supplierId) {
                                $directSearchSql .= " AND supplier_id = ?";
                                $directSearchParams[] = $supplierId;
                            }
                            
                            $directStockRow = $db->queryOne($directSearchSql, $directSearchParams);
                            $directQuantity = (float)($directStockRow['total_quantity'] ?? 0);
                            
                            if ($directQuantity > 0) {
                                $availableQuantity = $directQuantity;
                            }
                        }
                    }
                    
                    $resolved = true;
                    $availableUnit = 'kg';
                }
                break;
            case 'olive_oil':
                $oliveTableExists = $db->queryOne("SHOW TABLES LIKE 'olive_oil_stock'");
                if (!empty($oliveTableExists)) {
                    $availableQuantity = $checkStock('olive_oil_stock', 'quantity', $supplierId);
                    $resolved = true;
                    $availableUnit = 'kg';
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
            case 'tahini':
                // للطحينة، نستخدم tahini_stock مع supplier_id من السمسم
                $tahiniTableExists = $db->queryOne("SHOW TABLES LIKE 'tahini_stock'");
                if (!empty($tahiniTableExists)) {
                    $availableQuantity = $checkStock('tahini_stock', 'quantity', $supplierId);
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
        $honeyVarietyMeta = $rawDetail['honey_variety'] ?? null;
        
        // إذا كانت المادة عسل وكان مورد العسل محدد في نموذج إنشاء التشغيلة، استخدمه
        $isHoneyMaterialCheck = (mb_stripos($materialName, 'عسل') !== false || stripos($materialName, 'honey') !== false) ||
                               in_array(mb_strtolower($materialTypeMeta ?? '', 'UTF-8'), ['honey_raw', 'honey_filtered', 'honey'], true);
        if ($isHoneyMaterialCheck && $honeySupplierId !== null && $honeySupplierId > 0) {
            $materialSupplierMeta = $honeySupplierId;
        }
        
        // إذا كان نوع العسل غير محدد في rawDetail، حاول استخراجه من اسم المادة
        if (empty($honeyVarietyMeta) && (mb_stripos($materialName, 'عسل') !== false || stripos($materialName, 'honey') !== false)) {
            // تطبيع الاسم للبحث عن نوع العسل
            $nameForVarietyExtraction = trim($materialName);
            
            // 1. إذا كان الاسم يحتوي على " - "، استخرج نوع العسل
            if (mb_strpos($nameForVarietyExtraction, ' - ') !== false) {
                $parts = explode(' - ', $nameForVarietyExtraction, 2);
                if (count($parts) === 2) {
                    $honeyVarietyMeta = trim($parts[1]);
                }
            }
            // 2. إذا كان الاسم يحتوي على "-" بدون مسافات، استخرج نوع العسل
            elseif (mb_strpos($nameForVarietyExtraction, '-') !== false && mb_strpos($nameForVarietyExtraction, ' - ') === false) {
                $parts = explode('-', $nameForVarietyExtraction, 2);
                if (count($parts) === 2) {
                    $honeyVarietyMeta = trim($parts[1]);
                }
            }
            // 3. إذا كان الاسم يبدأ بـ "عسل "، استخرج نوع العسل
            elseif (mb_strpos($nameForVarietyExtraction, 'عسل ') === 0 && mb_strlen($nameForVarietyExtraction) > 5) {
                $parts = explode(' ', $nameForVarietyExtraction, 2);
                if (count($parts) === 2) {
                    $honeyVarietyMeta = trim($parts[1]);
                }
            }
            // 4. إذا كان الاسم يحتوي على "عسل" في أي مكان، حاول استخراج الكلمة التالية
            elseif (mb_stripos($nameForVarietyExtraction, 'عسل') !== false) {
                // البحث عن "عسل" واستخراج ما بعده
                $honeyPos = mb_stripos($nameForVarietyExtraction, 'عسل');
                if ($honeyPos !== false) {
                    $afterHoney = mb_substr($nameForVarietyExtraction, $honeyPos + mb_strlen('عسل'));
                    $afterHoney = trim($afterHoney);
                    // إزالة أي شرطات أو رموز في البداية
                    $afterHoney = preg_replace('/^[\s\-_]+/', '', $afterHoney);
                    if (!empty($afterHoney) && mb_strlen($afterHoney) > 1) {
                        // أخذ أول كلمة أو كلمتين كحد أقصى
                        $words = preg_split('/[\s\-_]+/', $afterHoney, 2);
                        $honeyVarietyMeta = trim($words[0]);
                    }
                }
            }
            
            // تنظيف نوع العسل المستخرج من أي رموز أو أرقام إضافية
            // لإزالة أي معلومات إضافية مثل "(KI002)" أو أرقام أو رموز
            if (!empty($honeyVarietyMeta)) {
                // إزالة الأقواس والمحتوى داخلها (مثل "سدر (KI002)" -> "سدر")
                $honeyVarietyMeta = preg_replace('/\s*\([^)]*\)\s*/', '', $honeyVarietyMeta);
                $honeyVarietyMeta = trim($honeyVarietyMeta);
                
                // إزالة أي رموز أو أرقام في نهاية الاسم إذا كانت منفصلة
                // لكن نتركها إذا كانت جزءًا من الاسم الأساسي
                $honeyVarietyMeta = preg_replace('/[\s\-_]+[A-Z0-9]+[\s\-_]*$/', '', $honeyVarietyMeta);
                $honeyVarietyMeta = trim($honeyVarietyMeta);
            }
        }
        
        $materialUnit = $raw['unit'] ?? ($rawDetail['unit'] ?? 'كجم');
        $materialUnitNormalized = $normalizeUnitLabel($materialUnit);
        
        // تطبيع اسم المادة للبحث (إزالة المسافات الزائدة وتوحيد الشرطات)
        $normalizeNameForSearch = static function(string $name): string {
            $name = trim($name);
            // استبدال أنواع مختلفة من الشرطات بمسافة واحدة (إزالة الشرطات)
            $name = preg_replace('/[\s\-_]+/', ' ', $name);
            return trim($name);
        };
        
        // إنشاء نسخ مختلفة من الاسم للبحث
        $normalizedMaterialName = $normalizeNameForSearch($materialName);
        // إنشاء نسخة مع شرطة (في حالة كان الاسم الأصلي يحتوي على شرطة)
        $materialNameWithDash = null;
        if (mb_strpos($materialName, '-') !== false || mb_strpos($materialName, ' - ') !== false) {
            // إذا كان الاسم الأصلي يحتوي على شرطة، أنشئ نسخة مع شرطة
            $parts = preg_split('/[\s\-_]+/', trim($materialName));
            if (count($parts) >= 2) {
                $materialNameWithDash = trim($parts[0]) . ' - ' . trim($parts[1]);
            }
        }
        $materialNameLower = mb_strtolower($normalizedMaterialName, 'UTF-8');
        
        // التحقق إذا كان الاسم الأصلي يحتوي على " - " (شرطة مع مسافات) - قد يكون مادتين منفصلتين
        $hasDashSeparator = (mb_strpos($materialName, ' - ') !== false);
        $materialParts = $hasDashSeparator ? array_map('trim', explode(' - ', $materialName, 2)) : [];
        
        // استخدام normalizeMaterialType لتحديد نوع المادة بدقة
        // هذا مهم للتعامل مع الحالات التي يكون فيها materialTypeMeta غير معروف (مثل "ingredient")
        $normalizedMaterialType = $normalizeMaterialType($materialTypeMeta, $materialName);
        
        // تحديث materialTypeMeta بالنوع المطبيع إذا تم اكتشاف نوع جديد
        if ($normalizedMaterialType !== 'other' && 
            !in_array($materialTypeMeta ?? '', ['honey_raw', 'honey_filtered', 'honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts'], true)) {
            $materialTypeMeta = $normalizedMaterialType;
        }
        
        // التحقق إذا كانت المادة عسل
        $isHoneyMaterial = (mb_stripos($materialName, 'عسل') !== false || stripos($materialName, 'honey') !== false) ||
                          ($normalizedMaterialType === 'honey_raw' || $normalizedMaterialType === 'honey_filtered' || $normalizedMaterialType === 'honey');
        
        // التحقق إذا كانت المادة عسل ولدينا معلومات محددة (مورد ونوع عسل)
        // في هذه الحالة، يجب البحث أولاً في honey_stock
        $isHoneyWithSpecificDetails = false;
        if ($isHoneyMaterial && 
            ($normalizedMaterialType === 'honey_raw' || $normalizedMaterialType === 'honey_filtered' || $normalizedMaterialType === 'honey') &&
            ($honeyVarietyMeta !== null && $honeyVarietyMeta !== '')) {
            $isHoneyWithSpecificDetails = true;
            
            error_log(sprintf(
                'Honey material detected: Name="%s", Original Type="%s", Normalized Type="%s", Supplier="%s", Variety="%s" - Will search in honey_stock first',
                $materialName,
                $materialTypeMeta ?? 'none',
                $normalizedMaterialType,
                $materialSupplierMeta ?? 'any',
                $honeyVarietyMeta
            ));
        }
        
        // البحث عن المادة في جدول المنتجات - محاولات متعددة
        // تخطي البحث في products إذا كانت المادة عسل ولدينا معلومات محددة
        $product = null;
        $availableQuantity = 0.0;
        $foundInProducts = false;
        
        if (!$isHoneyWithSpecificDetails) {
        
        // 1. البحث بمطابقة دقيقة بالاسم الأصلي
        $product = $db->queryOne(
            "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
            [$materialName]
        );
        
        if (!$product) {
            // 2. البحث بمطابقة دقيقة بعد تطبيع الاسم (بدون شرطة)
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$normalizedMaterialName]
            );
        }
        
        if (!$product && $materialNameWithDash !== null) {
            // 2.5. البحث بالاسم مع شرطة (في حالة كان الاسم الأصلي يحتوي على شرطة)
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$materialNameWithDash]
            );
        }
        
        if (!$product && $materialNameWithDash !== null) {
            // 2.6. البحث بالاسم مع شرطة بدون مسافات حول الشرطة
            $materialNameDashNoSpaces = str_replace(' - ', '-', $materialNameWithDash);
            $product = $db->queryOne(
                "SELECT id, name, quantity FROM products WHERE name = ? AND status = 'active' LIMIT 1",
                [$materialNameDashNoSpaces]
            );
        }
        
        if (!$product) {
            // 3. البحث بمطابقة جزئية (LIKE) - البحث عن أسماء مشابهة
            // إنشاء نمط بحث أكثر مرونة
            $searchTerms = array_filter(array_map('trim', explode(' ', $normalizedMaterialName)));
            if (!empty($searchTerms) && count($searchTerms) > 1) {
                $likePatterns = [];
                $likeParams = [];
                
                // البحث عن كل كلمة في الاسم
                foreach ($searchTerms as $term) {
                    if (mb_strlen($term) > 2) { // تجاهل الكلمات القصيرة جداً
                        $likePatterns[] = "name LIKE ?";
                        $likeParams[] = '%' . $term . '%';
                    }
                }
                
                if (!empty($likePatterns)) {
                    // البحث عن منتجات تحتوي على جميع الكلمات
                    $sql = "SELECT id, name, quantity FROM products 
                            WHERE (" . implode(' AND ', $likePatterns) . ")
                            AND status = 'active'
                            ORDER BY 
                                CASE 
                                    WHEN name = ? THEN 1
                                    WHEN name LIKE ? THEN 2
                                    WHEN LOWER(name) = ? THEN 3
                                    ELSE 4 
                                END,
                                quantity DESC
                            LIMIT 5";
                    
                    // إضافة معاملات ORDER BY (3 معاملات فقط لتطابق عدد الـ placeholders في CASE)
                    // ORDER BY يبحث عن: exact match, LIKE match, case-insensitive match
                    $orderByParams = [$materialName, $materialName . '%', $materialNameLower];
                    $allParams = array_merge($likeParams, $orderByParams);
                    $products = $db->query($sql, $allParams);
                    
                    if (!empty($products)) {
                        // اختيار أفضل تطابق (الأول في القائمة)
                        $product = $products[0];
                    }
                }
            }
            
            // 3.4. إذا كان الاسم يحتوي على " - " وفشل البحث، جرب البحث عن كل جزء بشكل منفصل
            if (!$product && $hasDashSeparator && count($materialParts) === 2) {
                $part1Product = null;
                $part2Product = null;
                
                // البحث عن الجزء الأول
                $part1 = trim($materialParts[0]);
                if (!empty($part1)) {
                    $part1Product = $db->queryOne(
                        "SELECT id, name, quantity FROM products 
                         WHERE (name = ? OR name LIKE ?) AND status = 'active'
                         ORDER BY 
                            CASE WHEN name = ? THEN 1 ELSE 2 END,
                            quantity DESC
                         LIMIT 1",
                        [$part1, '%' . $part1 . '%', $part1]
                    );
                }
                
                // البحث عن الجزء الثاني
                $part2 = trim($materialParts[1]);
                if (!empty($part2)) {
                    $part2Product = $db->queryOne(
                        "SELECT id, name, quantity FROM products 
                         WHERE (name = ? OR name LIKE ?) AND status = 'active'
                         ORDER BY 
                            CASE WHEN name = ? THEN 1 ELSE 2 END,
                            quantity DESC
                         LIMIT 1",
                        [$part2, '%' . $part2 . '%', $part2]
                    );
                }
                
                // إذا تم العثور على كلا الجزأين، استخدم الحد الأدنى من الكميات
                if ($part1Product && $part2Product) {
                    $qty1 = floatval($part1Product['quantity'] ?? 0);
                    $qty2 = floatval($part2Product['quantity'] ?? 0);
                    $minQty = min($qty1, $qty2);
                    
                    if ($minQty > 0) {
                        $product = $part1Product; // استخدام أول منتج كمرجع
                        $product['quantity'] = $minQty;
                        $product['matched_names'] = [$part1Product['name'], $part2Product['name']];
                        
                        error_log(sprintf(
                            'Material found by dash-separated parts: Template name="%s", Part1="%s" (qty=%s), Part2="%s" (qty=%s), Using min=%s',
                            $materialName,
                            $part1Product['name'],
                            $qty1,
                            $part2Product['name'],
                            $qty2,
                            $minQty
                        ));
                    }
                } elseif ($part1Product || $part2Product) {
                    // إذا تم العثور على جزء واحد فقط، استخدمه
                    $foundPart = $part1Product ?: $part2Product;
                    $product = $foundPart;
                    
                    error_log(sprintf(
                        'Material partially found by dash-separated parts: Template name="%s", Found="%s"',
                        $materialName,
                        $foundPart['name']
                    ));
                }
            }
            
            // 3.5. إذا فشل البحث المشترك وكان الاسم يحتوي على كلمات متعددة، جرب البحث عن كل كلمة بشكل منفصل
            if (!$product && !empty($searchTerms) && count($searchTerms) > 1) {
                $validTerms = array_filter($searchTerms, function($term) {
                    return mb_strlen($term) > 2;
                });
                
                if (count($validTerms) > 1) {
                    // البحث عن منتجات تحتوي على أي من الكلمات (OR)
                    $orPatterns = [];
                    $orParams = [];
                    
                    foreach ($validTerms as $term) {
                        $orPatterns[] = "name LIKE ?";
                        $orParams[] = '%' . $term . '%';
                    }
                    
                    if (!empty($orPatterns)) {
                        $sql = "SELECT id, name, quantity FROM products 
                                WHERE (" . implode(' OR ', $orPatterns) . ")
                                AND status = 'active'
                                ORDER BY 
                                    CASE 
                                        WHEN name = ? THEN 1
                                        WHEN name LIKE ? THEN 2
                                        WHEN LOWER(name) = ? THEN 3
                                        ELSE 4 
                                    END,
                                    quantity DESC
                                LIMIT 10";
                        
                        $allParams = array_merge($orParams, [$materialName, $materialName . '%', $materialNameLower]);
                        $products = $db->query($sql, $allParams);
                        
                        if (!empty($products)) {
                            // جمع الكميات من جميع المنتجات المطابقة
                            $totalQuantity = 0;
                            $matchedNames = [];
                            foreach ($products as $p) {
                                $qty = floatval($p['quantity'] ?? 0);
                                if ($qty > 0) {
                                    $totalQuantity += $qty;
                                    $matchedNames[] = $p['name'];
                                }
                            }
                            
                            if ($totalQuantity > 0) {
                                // استخدام أول منتج مطابق كمرجع، لكن الكمية الإجمالية من جميع المطابقات
                                $product = $products[0];
                                $product['quantity'] = $totalQuantity;
                                $product['matched_names'] = $matchedNames;
                                
                                error_log(sprintf(
                                    'Material found by individual word search: Template name="%s", Matched products: %s, Total quantity=%s',
                                    $materialName,
                                    implode(', ', $matchedNames),
                                    $totalQuantity
                                ));
                            }
                        }
                    }
                }
            }
            
            // 3.6. إذا لم يتم العثور على شيء بعد، جرب البحث بكلمة واحدة فقط (أول كلمة مهمة)
            if (!$product && !empty($searchTerms)) {
                $firstTerm = trim($searchTerms[0]);
                if (mb_strlen($firstTerm) > 2) {
                    $product = $db->queryOne(
                        "SELECT id, name, quantity FROM products 
                         WHERE name LIKE ? AND status = 'active'
                         ORDER BY 
                            CASE 
                                WHEN name = ? THEN 1
                                WHEN name LIKE ? THEN 2
                                ELSE 3 
                            END,
                            quantity DESC
                         LIMIT 1",
                        ['%' . $firstTerm . '%', $firstTerm, $firstTerm . '%']
                    );
                }
            }
        }
        
        if ($product) {
            $foundInProducts = true;
            $availableQuantity = floatval($product['quantity'] ?? 0);
            
            // تسجيل معلومات البحث للتحقق
            error_log(sprintf(
                'Material found in products: Template name="%s", Found name="%s", Available=%s, Required=%s',
                $materialName,
                $product['name'],
                $availableQuantity,
                $requiredQuantity
            ));
        }
        
        } // نهاية if (!$isHoneyWithSpecificDetails)
        
        // 4. البحث في جداول المخزون الخاصة (honey_stock, etc.)
        // إذا كانت المادة عسل ولكن نوع المادة غير محدد في metadata، استخدم النوع المطبيع
        if ($isHoneyMaterial && (empty($materialTypeMeta) || $normalizedMaterialType !== 'other')) {
            // استخدام النوع المطبيع إذا كان متاحًا، وإلا افتراض العسل المصفى
            if ($normalizedMaterialType === 'honey_raw' || $normalizedMaterialType === 'honey_filtered' || $normalizedMaterialType === 'honey') {
                $materialTypeMeta = $normalizedMaterialType;
            } elseif (empty($materialTypeMeta)) {
                // افتراض أن العسل المصفى هو الافتراضي للإنتاج
                $materialTypeMeta = 'honey_filtered';
            }
        }
        
        // البحث في جداول المخزون الخاصة - استخدام النوع المطبيع
        $materialTypeForSearch = $materialTypeMeta;
        if ($isHoneyMaterial && ($normalizedMaterialType === 'honey_raw' || $normalizedMaterialType === 'honey_filtered' || $normalizedMaterialType === 'honey')) {
            $materialTypeForSearch = $normalizedMaterialType;
        }
        
        // استخدام مورد العسل من نموذج إنشاء التشغيلة إذا كانت المادة عسل
        $supplierForSpecialStock = $materialSupplierMeta;
        if ($isHoneyMaterial && $honeySupplierId !== null && $honeySupplierId > 0) {
            $supplierForSpecialStock = $honeySupplierId;
        }
        
        $specialStock = $resolveSpecialStock($materialTypeForSearch, $supplierForSpecialStock, $materialName, $honeyVarietyMeta);
        if ($specialStock['resolved']) {
            $availableFromSpecial = $specialStock['quantity'];
            $availableFromSpecial = $convertQuantityUnit(
                $availableFromSpecial,
                $specialStock['unit'] ?? '',
                $materialUnitNormalized
            );
            
            if ($foundInProducts && !$isHoneyWithSpecificDetails) {
                // إضافة المخزون الخاص إلى المخزون الموجود في المنتجات (فقط إذا لم تكن عسل بمعلومات محددة)
                $availableQuantity += $availableFromSpecial;
            } else {
                // استخدام المخزون الخاص فقط
                $availableQuantity = $availableFromSpecial;
                
                // تسجيل تفصيلي للعسل مع معلومات محددة
                if ($isHoneyWithSpecificDetails) {
                    error_log(sprintf(
                        'Honey found in special stock (specific search): Template name="%s", Type="%s", Supplier="%s", Variety="%s", Available=%s, Required=%s',
                        $materialName,
                        $materialTypeMeta ?? 'unknown',
                        $supplierForSpecialStock ?? 'none',
                        $honeyVarietyMeta ?? 'none',
                        $availableQuantity,
                        $requiredQuantity
                    ));
                } else {
                    error_log(sprintf(
                        'Material found in special stock: Template name="%s", Type="%s", Available=%s, Required=%s',
                        $materialName,
                        $materialTypeMeta ?? 'unknown',
                        $availableQuantity,
                        $requiredQuantity
                    ));
                }
            }
        } elseif ($isHoneyMaterial && !$foundInProducts) {
            // إذا كانت المادة عسل ولم يتم العثور عليها في honey_stock أو products
            // جرب البحث في جدول products كبديل
            $honeySearchTerms = [];
            $honeySearchParams = [];
            
            // إضافة مصطلحات البحث بناءً على اسم المادة ونوع العسل
            if ($honeyVarietyMeta) {
                // البحث بنوع العسل مع "عسل" في أي ترتيب
                $honeySearchTerms[] = "(name LIKE ? OR name LIKE ? OR name LIKE ?)";
                $honeySearchParams[] = '%عسل%' . $honeyVarietyMeta . '%';
                $honeySearchParams[] = '%' . $honeyVarietyMeta . '%عسل%';
                $honeySearchParams[] = '%' . $honeyVarietyMeta . '%';
            }
            
            // إضافة البحث بالاسم الكامل
            $honeySearchTerms[] = "(name = ? OR name LIKE ?)";
            $honeySearchParams[] = $materialName;
            $honeySearchParams[] = $materialName . '%';
            
            // إضافة البحث بالاسم المطبيع
            if ($normalizedMaterialName !== $materialName) {
                $honeySearchTerms[] = "(name = ? OR name LIKE ?)";
                $honeySearchParams[] = $normalizedMaterialName;
                $honeySearchParams[] = $normalizedMaterialName . '%';
            }
            
            // إضافة البحث بالاسم مع شرطة إذا كان متوفراً
            if ($materialNameWithDash !== null) {
                $honeySearchTerms[] = "(name = ? OR name LIKE ?)";
                $honeySearchParams[] = $materialNameWithDash;
                $honeySearchParams[] = $materialNameWithDash . '%';
            }
            
            // إضافة بحث عام عن العسل إذا كان نوع العسل محدد
            if ($honeyVarietyMeta) {
                $honeySearchTerms[] = "name LIKE ?";
                $honeySearchParams[] = '%عسل%';
            }
            
            if (!empty($honeySearchTerms)) {
                $honeyProduct = $db->queryOne(
                    "SELECT id, name, quantity FROM products 
                     WHERE (" . implode(' OR ', $honeySearchTerms) . ") AND status = 'active'
                     ORDER BY 
                        CASE 
                            WHEN name = ? THEN 1
                            WHEN name LIKE ? THEN 2
                            WHEN name LIKE ? THEN 3
                            WHEN name LIKE ? THEN 4
                            ELSE 5 
                        END,
                        quantity DESC
                     LIMIT 1",
                    array_merge($honeySearchParams, [
                        $materialName, 
                        $materialName . '%', 
                        '%' . $materialName . '%',
                        ($honeyVarietyMeta ? '%' . $honeyVarietyMeta . '%' : '')
                    ])
                );
                
                if ($honeyProduct) {
                    $foundInProducts = true;
                    $product = $honeyProduct;
                    $availableQuantity = floatval($honeyProduct['quantity'] ?? 0);
                    
                    error_log(sprintf(
                        'Honey found in products as fallback: Template name="%s", Found name="%s", Available=%s, Required=%s',
                        $materialName,
                        $honeyProduct['name'],
                        $availableQuantity,
                        $requiredQuantity
                    ));
                } else {
                    error_log(sprintf(
                        'Honey NOT found in special stock or products: Template name="%s", Type="%s", Supplier="%s", Variety="%s", Required=%s',
                        $materialName,
                        $materialTypeMeta ?? 'unknown',
                        $supplierForSpecialStock ?? 'none',
                        $honeyVarietyMeta ?? 'none',
                        $requiredQuantity
                    ));
                }
            }
        }
        
        // التحقق من توفر الكمية المطلوبة
        if ($availableQuantity >= $requiredQuantity) {
            // المادة متوفرة بكمية كافية
            continue;
        }
        
        if ($availableQuantity > 0) {
            // المادة موجودة لكن الكمية غير كافية
            $insufficientMaterials[] = [
                'name' => $materialName,
                'required' => $requiredQuantity,
                'available' => $availableQuantity,
                'type' => 'مواد خام',
                'unit' => $materialUnit,
                'found_name' => $product['name'] ?? null,
                'found_in' => $foundInProducts ? 'products' : ($specialStock['resolved'] ? 'special_stock' : 'none')
            ];
        } else {
            // المادة غير موجودة
            $missingMaterials[] = [
                'name' => $materialName,
                'type' => 'مواد خام',
                'unit' => $materialUnit,
                'searched_name' => $normalizedMaterialName
            ];
            
            // تسجيل تفصيلي للمادة غير الموجودة
            $supplierForLog = $isHoneyMaterial && ($honeySupplierId !== null && $honeySupplierId > 0) ? $honeySupplierId : ($materialSupplierMeta ?? 'none');
            error_log(sprintf(
                'Material NOT found: Template name="%s", Normalized="%s", Type="%s", Supplier="%s", Variety="%s"',
                $materialName,
                $normalizedMaterialName,
                $materialTypeMeta ?? 'unknown',
                $supplierForLog,
                $honeyVarietyMeta ?? 'none'
            ));
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
        $missingDetails = [];
        foreach ($missingMaterials as $m) {
            $detail = $m['name'] . ' (' . $m['type'] . ')';
            if (!empty($m['searched_name']) && $m['searched_name'] !== $m['name']) {
                $detail .= ' [تم البحث عن: ' . $m['searched_name'] . ']';
            }
            $missingDetails[] = $detail;
        }
        $errorMessages[] = 'مواد غير موجودة في المخزون: ' . implode(', ', $missingDetails);
    }
    
    if (!empty($insufficientMaterials)) {
        $insufficientDetails = [];
        foreach ($insufficientMaterials as $mat) {
            $unit = $mat['unit'] ?? '';
            $detail = sprintf(
                '%s (%s): مطلوب %s %s، متوفر %s %s',
                $mat['name'],
                $mat['type'],
                number_format($mat['required'], 2),
                $unit,
                number_format($mat['available'], 2),
                $unit
            );
            
            // إضافة معلومات إضافية إذا كانت متوفرة
            if (!empty($mat['found_name']) && $mat['found_name'] !== $mat['name']) {
                $detail .= ' [تم العثور على: ' . $mat['found_name'] . ']';
            }
            if (!empty($mat['found_in']) && $mat['found_in'] !== 'products') {
                $detail .= ' [في: ' . ($mat['found_in'] === 'special_stock' ? 'مخزون خاص' : $mat['found_in']) . ']';
            }
            
            $insufficientDetails[] = $detail;
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
        // إيقاف المعالجة لمنع التكرار
        $_SESSION['error_message'] = $error;
        // محاولة إعادة التوجيه فقط إذا لم يتم إرسال الرؤوس بعد
        if (!headers_sent()) {
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            // إذا تم إرسال الرؤوس بالفعل، فقط تجاهل المعالجة
            // سيتم عرض رسالة الخطأ من $_SESSION['error_message']
            $action = ''; // إلغاء المعالجة
        }
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
                $materialHoneyStatesInput = $_POST['material_honey_states'] ?? [];
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

                // جلب القالب من الجدول المناسب بناءً على نوع القالب
                $template = null;
                
                if ($templateType === 'unified') {
                    // القوالب الموحدة من جدول unified_product_templates
                    $template = $db->queryOne(
                        "SELECT upt.*, 
                                'unified' AS template_type,
                                upt.product_name,
                                pr.id as product_id,
                                pr.name as product_name_from_products,
                                pr.unit_price as product_unit_price
                         FROM unified_product_templates upt
                         LEFT JOIN products pr ON upt.product_name = pr.name
                         WHERE upt.id = ?",
                        [$templateId]
                    );
                    
                    // إذا كان product_name فارغاً، استخدم product_name_from_products
                    if ($template && empty($template['product_name']) && !empty($template['product_name_from_products'])) {
                        $template['product_name'] = $template['product_name_from_products'];
                    }
                    
                    // إذا لم يكن هناك unit_price في القالب، جرب جلبها من product_templates المرتبط
                    if ($template && (empty($template['unit_price']) || (float)($template['unit_price'] ?? 0) <= 0)) {
                        $relatedTemplate = $db->queryOne(
                            "SELECT unit_price FROM product_templates WHERE source_template_id = ? AND unit_price IS NOT NULL AND unit_price > 0 LIMIT 1",
                            [$templateId]
                        );
                        if ($relatedTemplate && !empty($relatedTemplate['unit_price'])) {
                            $template['unit_price'] = $relatedTemplate['unit_price'];
                        }
                    }
                } else {
                    // القوالب التقليدية من جدول product_templates
                    $template = $db->queryOne(
                        "SELECT pt.*, pr.id as product_id, pr.name as product_name, pr.unit_price as product_unit_price
                         FROM product_templates pt
                         LEFT JOIN products pr ON pt.product_name = pr.name
                         WHERE pt.id = ?",
                        [$templateId]
                    );
                }

                if (!$template) {
                    throw new Exception('القالب غير موجود');
                }
                
                // التأكد من وجود product_name في القالب - محاولة من مصادر متعددة
                if (empty($template['product_name']) || trim($template['product_name']) === '') {
                    // 1. محاولة جلب اسم المنتج من جدول products إذا كان product_id موجوداً
                    if (!empty($template['product_id'])) {
                        $product = $db->queryOne("SELECT name FROM products WHERE id = ?", [$template['product_id']]);
                        if ($product && !empty($product['name']) && trim($product['name']) !== '') {
                            $template['product_name'] = trim($product['name']);
                        }
                    }
                    
                    // 2. إذا كان template_type = 'unified'، جلب من unified_product_templates مباشرة
                    if ((empty($template['product_name']) || trim($template['product_name']) === '') && $templateType === 'unified') {
                        $unifiedTemplate = $db->queryOne(
                            "SELECT product_name FROM unified_product_templates WHERE id = ?",
                            [$templateId]
                        );
                        if ($unifiedTemplate && !empty($unifiedTemplate['product_name']) && trim($unifiedTemplate['product_name']) !== '') {
                            $template['product_name'] = trim($unifiedTemplate['product_name']);
                        }
                    }
                    
                    // 3. إذا كان template_type = 'legacy' أو غير محدد، جلب من product_templates مباشرة
                    if ((empty($template['product_name']) || trim($template['product_name']) === '') && $templateType !== 'unified') {
                        $legacyTemplate = $db->queryOne(
                            "SELECT product_name FROM product_templates WHERE id = ?",
                            [$templateId]
                        );
                        if ($legacyTemplate && !empty($legacyTemplate['product_name']) && trim($legacyTemplate['product_name']) !== '') {
                            $template['product_name'] = trim($legacyTemplate['product_name']);
                        }
                    }
                    
                    // 4. إذا لم يتم العثور على product_name، استخدم اسم القالب من details_json كبديل
                    if ((empty($template['product_name']) || trim($template['product_name']) === '') && !empty($template['details_json'])) {
                        try {
                            $decodedDetails = json_decode((string)$template['details_json'], true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails)) {
                                if (!empty($decodedDetails['product_name']) && trim($decodedDetails['product_name']) !== '') {
                                    $template['product_name'] = trim($decodedDetails['product_name']);
                                }
                            }
                        } catch (Exception $e) {
                            error_log('Error parsing template details_json: ' . $e->getMessage());
                        }
                    }
                }
                
                // تنظيف product_name من أي مسافات زائدة
                if (!empty($template['product_name'])) {
                    $template['product_name'] = trim($template['product_name']);
                }
                
                // التحقق النهائي من وجود product_name قبل المتابعة
                if (empty($template['product_name']) || trim($template['product_name']) === '') {
                    error_log("Template product_name is empty - Template ID: {$templateId}, Type: {$templateType}");
                    throw new Exception('يجب تحديد اسم المنتج الحقيقي في قالب الإنتاج قبل إنشاء المنتج. يرجى مراجعة بيانات القالب.');
                }

                // الحصول على المواد الخام للتحقق من العسل واستخراج مورد العسل
                // استخدام الجدول المناسب بناءً على نوع القالب
                if ($templateType === 'unified') {
                    $rawMaterialsForCheck = $db->query(
                        "SELECT id, material_name, quantity_per_unit, unit, material_type, honey_variety
                         FROM template_raw_materials
                         WHERE template_id = ?",
                        [$templateId]
                    );
                } else {
                    $rawMaterialsForCheck = $db->query(
                        "SELECT id, material_name, quantity_per_unit, unit
                         FROM product_template_raw_materials
                         WHERE template_id = ?",
                        [$templateId]
                    );
                }
                
                // استخراج مورد العسل من $materialSuppliers إذا كانت المادة عسل
                $honeySupplierIdForCheck = null;
                if (!empty($rawMaterialsForCheck) && !empty($template['details_json'])) {
                    try {
                        $decodedTemplateDetails = json_decode((string) $template['details_json'], true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedTemplateDetails)) {
                            $rawDetailsList = $decodedTemplateDetails['raw_materials'] ?? [];
                            if (is_array($rawDetailsList)) {
                                foreach ($rawMaterialsForCheck as $rawMaterial) {
                                    $rawId = (int)($rawMaterial['id'] ?? 0);
                                    $rawKey = 'raw_' . $rawId;
                                    $materialName = trim((string)($rawMaterial['material_name'] ?? ''));
                                    
                                    // البحث في تفاصيل القالب
                                    foreach ($rawDetailsList as $rawDetail) {
                                        if (!is_array($rawDetail)) {
                                            continue;
                                        }
                                        $detailMaterialId = isset($rawDetail['template_item_id']) ? (int)$rawDetail['template_item_id'] : (isset($rawDetail['id']) ? (int)$rawDetail['id'] : 0);
                                        $detailMaterialName = trim((string)($rawDetail['name'] ?? $rawDetail['material_name'] ?? ''));
                                        
                                        // التحقق إذا كانت المادة مطابقة وكانت عسل
                                        if (($detailMaterialId === $rawId || mb_strtolower($detailMaterialName, 'UTF-8') === mb_strtolower($materialName, 'UTF-8')) &&
                                            (mb_stripos($materialName, 'عسل') !== false || stripos($materialName, 'honey') !== false ||
                                             in_array(mb_strtolower($rawDetail['type'] ?? '', 'UTF-8'), ['honey_raw', 'honey_filtered', 'honey'], true))) {
                                            // إذا كانت المادة عسل، استخدم المورد المحدد في النموذج
                                            if (isset($materialSuppliers[$rawKey]) && (int)$materialSuppliers[$rawKey] > 0) {
                                                $honeySupplierIdForCheck = (int)$materialSuppliers[$rawKey];
                                                break 2; // خروج من كلا الحلقات
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Error extracting honey supplier for check: ' . $e->getMessage());
                    }
                }

                // التحقق من توفر جميع المكونات قبل بدء عملية الإنتاج
                $materialsCheck = checkMaterialsAvailability($db, $templateId, $quantity, $materialSuppliers, $honeySupplierIdForCheck);
                if (!$materialsCheck['available']) {
                    $db->rollBack();
                    throw new Exception('لا يمكن إتمام عملية الإنتاج: ' . $materialsCheck['message']);
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
                $packagingMaterialCodeColumnExists = $packagingTableExists && productionColumnExists('packaging_materials', 'material_id');
                $packagingSelectColumnsParts = ['id', 'name', 'unit'];
                if ($packagingProductColumnExists) {
                    $packagingSelectColumnsParts[] = 'product_id';
                }
                if ($packagingMaterialCodeColumnExists) {
                    $packagingSelectColumnsParts[] = 'material_id';
                }
                $packagingSelectColumns = implode(', ', $packagingSelectColumnsParts);

                $normalizePackagingCodeKey = static function (?string $code): ?string {
                    if ($code === null) {
                        return null;
                    }
                    $code = strtoupper(trim((string)$code));
                    if ($code === '') {
                        return null;
                    }
                    $code = preg_replace('/[^A-Z0-9]/', '', $code);
                    return $code !== '' ? $code : null;
                };

                $formatPackagingCode = static function (?string $codeKey): ?string {
                    if ($codeKey === null) {
                        return null;
                    }
                    if (preg_match('/^(PKG)(\d{1,})$/', $codeKey, $matches)) {
                        return $matches[1] . '-' . str_pad($matches[2], 3, '0', STR_PAD_LEFT);
                    }
                    return $codeKey;
                };
                // تم إزالة منطق الخصم التلقائي القديم - سيتم استخدام نظام جديد يعتمد على نوع الكرتونة
                $packagingMaterialCodeCache = [];
                $fetchPackagingMaterialByCode = static function (string $code) use (
                    $db,
                    $packagingTableExists,
                    &$packagingMaterialCodeCache,
                    $normalizePackagingCodeKey,
                    $formatPackagingCode,
                    $packagingProductColumnExists
                ): ?array {
                    if (!$packagingTableExists) {
                        return null;
                    }
                    $codeKey = $normalizePackagingCodeKey($code);
                    if ($codeKey === null) {
                        return null;
                    }
                    if (array_key_exists($codeKey, $packagingMaterialCodeCache)) {
                        return $packagingMaterialCodeCache[$codeKey];
                    }
                    try {
                        $selectCols = 'id, material_id, name, unit';
                        if ($packagingProductColumnExists) {
                            $selectCols .= ', product_id';
                        }
                        $row = $db->queryOne(
                            "SELECT {$selectCols} 
                             FROM packaging_materials 
                             WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(material_id, '-', ''), ' ', ''), '_', ''), '.', '')) = ? 
                             LIMIT 1",
                            [$codeKey]
                        );
                        if ($row) {
                            $rowCodeKey = $normalizePackagingCodeKey($row['material_id'] ?? '') ?? $codeKey;
                            $packagingMaterialCodeCache[$codeKey] = [
                                'id' => (int)($row['id'] ?? 0),
                                'code_key' => $rowCodeKey,
                                'code' => $formatPackagingCode($rowCodeKey),
                                'name' => $row['name'] ?? null,
                                'unit' => $row['unit'] ?? null,
                                'product_id' => isset($row['product_id']) ? (int)$row['product_id'] : null
                            ];
                        } else {
                            $packagingMaterialCodeCache[$codeKey] = null;
                        }
                    } catch (Throwable $e) {
                        error_log('Packaging material lookup failed for code ' . $codeKey . ': ' . $e->getMessage());
                        $packagingMaterialCodeCache[$codeKey] = null;
                    }
                    return $packagingMaterialCodeCache[$codeKey];
                };
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

                // تهيئة متغيرات تتبع المواد في القالب
                $templateIncludesPkg003 = false;
                $templateIncludesPkg004 = false;
                $templateIncludesPkg005 = false;
                $templateIncludesPkg006 = false;
                $templateIncludesPkg007 = false;
                $templateIncludesPkg009 = false;
                $templateIncludesPkg010 = false;
                $templateIncludesPkg011 = false;
                $templateIncludesPkg012 = false;
                $templateIncludesPkg036 = false;
                $templateIncludesPkg042 = false;

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

                    $packagingMaterialCode = null;
                    $packagingMaterialCodeKey = null;
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
                        if ($packagingMaterialCodeColumnExists && !empty($packagingRow['material_id'])) {
                            $packagingMaterialCode = (string)$packagingRow['material_id'];
                            $packagingMaterialCodeKey = $normalizePackagingCodeKey($packagingMaterialCode);
                        }
                    }

                    $packagingMaterialCodeNormalized = null;
                    if ($packagingMaterialCodeKey !== null) {
                        $packagingMaterialCodeNormalized = $formatPackagingCode($packagingMaterialCodeKey);
                    } elseif ($packagingName !== '') {
                        if (preg_match('/(PKG[\s-]*\d{3,})/i', $packagingName, $codeMatches)) {
                            $packagingMaterialCodeKey = $normalizePackagingCodeKey($codeMatches[1]);
                            if ($packagingMaterialCodeKey !== null) {
                                $packagingMaterialCodeNormalized = $formatPackagingCode($packagingMaterialCodeKey);
                            }
                        }
                    }
                    if (
                        $packagingMaterialId <= 0 &&
                        $packagingTableExists &&
                        $packagingMaterialCodeKey !== null
                    ) {
                        $resolvedByCode = $fetchPackagingMaterialByCode(
                            $packagingMaterialCodeNormalized ?? $formatPackagingCode($packagingMaterialCodeKey) ?? $packagingMaterialCodeKey
                        );
                        if ($resolvedByCode) {
                            if (!empty($resolvedByCode['id'])) {
                                $packagingMaterialId = (int)$resolvedByCode['id'];
                            }
                            if ($packagingName === '' && !empty($resolvedByCode['name'])) {
                                $packagingName = (string)$resolvedByCode['name'];
                            }
                            if (!empty($resolvedByCode['unit'])) {
                                $packagingUnit = (string)$resolvedByCode['unit'];
                            }
                            if (!$packagingProductId && !empty($resolvedByCode['product_id'])) {
                                $packagingProductId = (int)$resolvedByCode['product_id'];
                            }
                            if (!empty($resolvedByCode['code_key'])) {
                                $packagingMaterialCodeKey = $resolvedByCode['code_key'];
                            }
                            if (!empty($resolvedByCode['code'])) {
                                $packagingMaterialCodeNormalized = $resolvedByCode['code'];
                            }
                        }
                    }
                    if ($packagingMaterialCodeNormalized === null && $packagingTableExists && $packagingMaterialId > 0) {
                        try {
                            $codeLookup = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ? LIMIT 1",
                                [$packagingMaterialId]
                            );
                            if (!empty($codeLookup['material_id'])) {
                                $packagingMaterialCodeKey = $normalizePackagingCodeKey($codeLookup['material_id']);
                                if ($packagingMaterialCodeKey !== null) {
                                    $packagingMaterialCodeNormalized = $formatPackagingCode($packagingMaterialCodeKey);
                                } else {
                                $packagingMaterialCodeNormalized = strtoupper(trim((string)$codeLookup['material_id']));
                                }
                            }
                        } catch (Throwable $codeLookupError) {
                            error_log('Failed to fetch packaging material code for ID ' . $packagingMaterialId . ': ' . $codeLookupError->getMessage());
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

                    // تسجيل معلومات المادة للتتبع (لجميع المواد للتشخيص)
                    if ($packagingMaterialId > 0) {
                        $logMaterialCode = $packagingMaterialCodeKey ?? ($packagingMaterialCodeNormalized ?? 'NULL');
                        $logName = $packagingName ?? 'NULL';
                        // تسجيل جميع المواد التي تحتوي على PKG في الكود أو الاسم
                        if (stripos($logMaterialCode, 'PKG') !== false || stripos($logName, 'PKG') !== false) {
                            error_log('Processing packaging item: ID=' . $packagingMaterialId . ', CodeKey=' . ($packagingMaterialCodeKey ?? 'NULL') . ', CodeNormalized=' . ($packagingMaterialCodeNormalized ?? 'NULL') . ', Name=' . $logName);
                        }
                    }

                    // تم إزالة منطق اكتشاف المواد المحفزة - النظام يعتمد الآن فقط على نوع الكرتونة المخزن في القالب
                    $isPkg010 = false;
                    if ($packagingMaterialCodeKey === 'PKG010') {
                        $isPkg010 = true;
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        if ($normalizedCodeKey === 'PKG010') {
                            $isPkg010 = true;
                        }
                    } elseif ($packagingMaterialId > 0 && $packagingTableExists) {
                        // محاولة أخيرة: البحث المباشر في قاعدة البيانات
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                if ($checkCodeKey === 'PKG010') {
                                    $isPkg010 = true;
                                    $packagingMaterialCodeKey = 'PKG010';
                                }
                            }
                        } catch (Throwable $checkError) {
                            // تجاهل الخطأ
                        }
                    }
                    
                    if (!$templateIncludesPkg010 && $isPkg010) {
                        $templateIncludesPkg010 = true;
                        error_log('PKG-010 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-002 deduction if quantity >= 6');
                    }

                    // اكتشاف PKG-003 بعدة طرق للتأكد
                    $isPkg003 = false;
                    if ($packagingMaterialCodeKey === 'PKG003') {
                        $isPkg003 = true;
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        if ($normalizedCodeKey === 'PKG003') {
                            $isPkg003 = true;
                        }
                    } elseif ($packagingMaterialId > 0 && $packagingTableExists) {
                        // محاولة أخيرة: البحث المباشر في قاعدة البيانات
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                if ($checkCodeKey === 'PKG003') {
                                    $isPkg003 = true;
                                    $packagingMaterialCodeKey = 'PKG003';
                                }
                            }
                        } catch (Throwable $checkError) {
                            // تجاهل الخطأ
                        }
                    }
                    
                    if (!$templateIncludesPkg003 && $isPkg003) {
                        $templateIncludesPkg003 = true;
                        error_log('PKG-003 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-002 deduction if quantity >= 24');
                    }

                    // اكتشاف PKG-007 بعدة طرق للتأكد
                    $isPkg007 = false;
                    if ($packagingMaterialCodeKey === 'PKG007') {
                        $isPkg007 = true;
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        if ($normalizedCodeKey === 'PKG007') {
                            $isPkg007 = true;
                        }
                    } elseif ($packagingMaterialId > 0 && $packagingTableExists) {
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                if ($checkCodeKey === 'PKG007') {
                                    $isPkg007 = true;
                                    $packagingMaterialCodeKey = 'PKG007';
                                }
                            }
                        } catch (Throwable $checkError) {
                            // تجاهل الخطأ
                        }
                    }

                    if (!$templateIncludesPkg007 && $isPkg007) {
                        $templateIncludesPkg007 = true;
                        error_log('PKG-007 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-041 deduction if quantity >= 24');
                    }

                    // اكتشاف PKG-005 بعدة طرق للتأكد
                    $isPkg005 = false;
                    if ($packagingMaterialCodeKey === 'PKG005') {
                        $isPkg005 = true;
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        if ($normalizedCodeKey === 'PKG005') {
                            $isPkg005 = true;
                        }
                    } elseif ($packagingMaterialId > 0 && $packagingTableExists) {
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                if ($checkCodeKey === 'PKG005') {
                                    $isPkg005 = true;
                                    $packagingMaterialCodeKey = 'PKG005';
                                }
                            }
                        } catch (Throwable $checkError) {
                            // تجاهل الخطأ
                        }
                    }

                    if (!$templateIncludesPkg005 && $isPkg005) {
                        $templateIncludesPkg005 = true;
                        error_log('PKG-005 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-041 deduction if quantity >= 18');
                    }

                    // اكتشاف PKG-006 بعدة طرق للتأكد
                    $isPkg006 = false;
                    if ($packagingMaterialCodeKey === 'PKG006') {
                        $isPkg006 = true;
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        if ($normalizedCodeKey === 'PKG006') {
                            $isPkg006 = true;
                        }
                    } elseif ($packagingMaterialId > 0 && $packagingTableExists) {
                        // محاولة أخيرة: البحث المباشر في قاعدة البيانات
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                if ($checkCodeKey === 'PKG006') {
                                    $isPkg006 = true;
                                    $packagingMaterialCodeKey = 'PKG006';
                                }
                            }
                        } catch (Throwable $checkError) {
                            // تجاهل الخطأ
                        }
                    }
                    
                    if (!$templateIncludesPkg006 && $isPkg006) {
                        $templateIncludesPkg006 = true;
                        error_log('PKG-006 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-041 deduction if quantity >= 18');
                    }

                    // اكتشاف PKG-042 بعدة طرق للتأكد
                    $isPkg042 = false;
                    if ($packagingMaterialCodeKey === 'PKG042') {
                        $isPkg042 = true;
                        error_log('PKG-042 detected by codeKey match: packagingMaterialCodeKey=' . $packagingMaterialCodeKey);
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        error_log('PKG-042 check: packagingMaterialCodeNormalized=' . $packagingMaterialCodeNormalized . ', normalized=' . $normalizedCodeKey);
                        if ($normalizedCodeKey === 'PKG042') {
                            $isPkg042 = true;
                            error_log('PKG-042 detected by normalized code: packagingMaterialCodeNormalized=' . $packagingMaterialCodeNormalized . ', normalized=' . $normalizedCodeKey);
                        }
                    }
                    // محاولة أخيرة: البحث المباشر في قاعدة البيانات (دائماً، حتى لو فشلت المحاولات السابقة)
                    if (!$isPkg042 && $packagingMaterialId > 0 && $packagingTableExists) {
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                error_log('PKG-042 database check: packagingMaterialId=' . $packagingMaterialId . ', material_id=' . $materialCodeCheck['material_id'] . ', normalized=' . $checkCodeKey);
                                if ($checkCodeKey === 'PKG042') {
                                    $isPkg042 = true;
                                    $packagingMaterialCodeKey = 'PKG042';
                                    error_log('PKG-042 detected by database lookup: material_id=' . $materialCodeCheck['material_id'] . ', packagingMaterialId=' . $packagingMaterialId);
                                }
                            } else {
                                error_log('PKG-042 database check: No material found for packagingMaterialId=' . $packagingMaterialId);
                            }
                        } catch (Throwable $checkError) {
                            error_log('PKG-042 database lookup error: ' . $checkError->getMessage());
                        }
                    }
                    
                    if (!$templateIncludesPkg042 && $isPkg042) {
                        $templateIncludesPkg042 = true;
                        error_log('PKG-042 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-001 deduction if quantity >= 12');
                    }

                    // اكتشاف PKG-011 بعدة طرق للتأكد
                    $isPkg011 = false;
                    if ($packagingMaterialCodeKey === 'PKG011') {
                        $isPkg011 = true;
                        error_log('PKG-011 detected by codeKey match: packagingMaterialCodeKey=' . $packagingMaterialCodeKey);
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        error_log('PKG-011 check: packagingMaterialCodeNormalized=' . $packagingMaterialCodeNormalized . ', normalized=' . $normalizedCodeKey);
                        if ($normalizedCodeKey === 'PKG011') {
                            $isPkg011 = true;
                            error_log('PKG-011 detected by normalized code: packagingMaterialCodeNormalized=' . $packagingMaterialCodeNormalized . ', normalized=' . $normalizedCodeKey);
                        }
                    }
                    // محاولة أخيرة: البحث المباشر في قاعدة البيانات (دائماً، حتى لو فشلت المحاولات السابقة)
                    if (!$isPkg011 && $packagingMaterialId > 0 && $packagingTableExists) {
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                error_log('PKG-011 database check: packagingMaterialId=' . $packagingMaterialId . ', material_id=' . $materialCodeCheck['material_id'] . ', normalized=' . $checkCodeKey);
                                if ($checkCodeKey === 'PKG011') {
                                    $isPkg011 = true;
                                    $packagingMaterialCodeKey = 'PKG011';
                                    error_log('PKG-011 detected by database lookup: material_id=' . $materialCodeCheck['material_id'] . ', packagingMaterialId=' . $packagingMaterialId);
                                }
                            } else {
                                error_log('PKG-011 database check: No material found for packagingMaterialId=' . $packagingMaterialId);
                            }
                        } catch (Throwable $checkError) {
                            error_log('PKG-011 database lookup error: ' . $checkError->getMessage());
                        }
                    }
                    
                    if (!$templateIncludesPkg011 && $isPkg011) {
                        $templateIncludesPkg011 = true;
                        error_log('PKG-011 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-001 deduction if quantity >= 12');
                    }

                    // اكتشاف PKG-012 بعدة طرق للتأكد
                    $isPkg012 = false;
                    if ($packagingMaterialCodeKey === 'PKG012') {
                        $isPkg012 = true;
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        if ($normalizedCodeKey === 'PKG012') {
                            $isPkg012 = true;
                        }
                    } elseif ($packagingMaterialId > 0 && $packagingTableExists) {
                        // محاولة أخيرة: البحث المباشر في قاعدة البيانات
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                if ($checkCodeKey === 'PKG012') {
                                    $isPkg012 = true;
                                    $packagingMaterialCodeKey = 'PKG012';
                                }
                            }
                        } catch (Throwable $checkError) {
                            // تجاهل الخطأ
                        }
                    }
                    
                    if (!$templateIncludesPkg012 && $isPkg012) {
                        $templateIncludesPkg012 = true;
                        error_log('PKG-012 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-041 deduction if quantity >= 24');
                    }

                    // اكتشاف PKG-036 بعدة طرق للتأكد
                    $isPkg036 = false;
                    if ($packagingMaterialCodeKey === 'PKG036') {
                        $isPkg036 = true;
                        error_log('PKG-036 detected by codeKey match: packagingMaterialCodeKey=' . $packagingMaterialCodeKey);
                    } elseif ($packagingMaterialCodeNormalized !== null) {
                        $normalizedCodeKey = $normalizePackagingCodeKey($packagingMaterialCodeNormalized);
                        error_log('PKG-036 check: packagingMaterialCodeNormalized=' . $packagingMaterialCodeNormalized . ', normalized=' . $normalizedCodeKey);
                        if ($normalizedCodeKey === 'PKG036') {
                            $isPkg036 = true;
                            error_log('PKG-036 detected by normalized code: packagingMaterialCodeNormalized=' . $packagingMaterialCodeNormalized . ', normalized=' . $normalizedCodeKey);
                        }
                    }
                    // محاولة أخيرة: البحث المباشر في قاعدة البيانات (دائماً، حتى لو فشلت المحاولات السابقة)
                    if (!$isPkg036 && $packagingMaterialId > 0 && $packagingTableExists) {
                        try {
                            $materialCodeCheck = $db->queryOne(
                                "SELECT material_id FROM packaging_materials WHERE id = ?",
                                [$packagingMaterialId]
                            );
                            if ($materialCodeCheck && !empty($materialCodeCheck['material_id'])) {
                                $checkCodeKey = $normalizePackagingCodeKey($materialCodeCheck['material_id']);
                                error_log('PKG-036 database check: packagingMaterialId=' . $packagingMaterialId . ', material_id=' . $materialCodeCheck['material_id'] . ', normalized=' . $checkCodeKey);
                                if ($checkCodeKey === 'PKG036') {
                                    $isPkg036 = true;
                                    $packagingMaterialCodeKey = 'PKG036';
                                    error_log('PKG-036 detected by database lookup: material_id=' . $materialCodeCheck['material_id'] . ', packagingMaterialId=' . $packagingMaterialId);
                                }
                            } else {
                                error_log('PKG-036 database check: No material found for packagingMaterialId=' . $packagingMaterialId);
                            }
                        } catch (Throwable $checkError) {
                            error_log('PKG-036 database lookup error: ' . $checkError->getMessage());
                        }
                    }
                    
                    if (!$templateIncludesPkg036 && $isPkg036) {
                        $templateIncludesPkg036 = true;
                        error_log('PKG-036 detected in template (ID=' . $packagingMaterialId . '). Will trigger PKG-002 deduction if quantity >= 6');
                    }

                    $materialsConsumption['packaging'][] = [
                        'material_id' => $packagingMaterialId > 0 ? $packagingMaterialId : null,
                        'quantity' => (float)($pkg['quantity_per_unit'] ?? 1.0) * $quantity,
                        'name' => $packagingName !== '' ? $packagingName : 'مادة تعبئة',
                        'unit' => $packagingUnit,
                        'product_id' => $packagingProductId,
                        'supplier_id' => $selectedSupplierId,
                        'template_item_id' => (int)($pkg['id'] ?? 0),
                        'material_code' => $packagingMaterialCodeNormalized
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

                // تم إزالة منطق الخصم التلقائي القديم - سيتم استخدام نظام جديد يعتمد على نوع الكرتونة
                if (false) { // تم تعطيل الكود القديم
                    error_log('PKG-001 deduction logic triggered: templateIncludesPkg009=' . ($templateIncludesPkg009 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-001 عند وجود PKG-009 لكل 12 وحدة منتجة
                    $pkg001CodeKey = 'PKG001';
                    $pkg001DisplayCode = $formatPackagingCode($pkg001CodeKey) ?? 'PKG-001';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg001Id = null;
                    $pkg001Name = 'مادة تعبئة PKG-001';
                    $pkg001Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG001'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG001%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG001%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg001Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg001Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg001Unit = $directSearch['unit'];
                                }
                                error_log('PKG-001 found by direct search: ID=' . $pkg001Id . ', Name=' . $pkg001Name);
                            } else {
                                // محاولة البحث بالكود
                                $pkg001Info = $fetchPackagingMaterialByCode($pkg001DisplayCode) ?? [];
                    if (!empty($pkg001Info['id'])) {
                        $pkg001Id = (int)$pkg001Info['id'];
                                    if (!empty($pkg001Info['name'])) {
                                        $pkg001Name = $pkg001Info['name'];
                                    }
                                    if (!empty($pkg001Info['unit'])) {
                                        $pkg001Unit = $pkg001Info['unit'];
                                    }
                                    error_log('PKG-001 found by code search: ID=' . $pkg001Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg001ByName = $resolvePackagingByName('PKG-001');
                                    if ($pkg001ByName && !empty($pkg001ByName['id'])) {
                                        $pkg001Id = (int)$pkg001ByName['id'];
                                        if (!empty($pkg001ByName['name'])) {
                                            $pkg001Name = $pkg001ByName['name'];
                                        }
                                        if (!empty($pkg001ByName['unit'])) {
                                            $pkg001Unit = $pkg001ByName['unit'];
                                        }
                                        error_log('PKG-001 found by name search: ID=' . $pkg001Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-001 search error: ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg001Id === null) {
                        error_log('PKG-001 NOT FOUND in database. Automatic deduction will be skipped.');
                    }

                        $quantityForBoxes = (int)floor((float)$quantity);
                        $additionalPkg001Qty = intdiv(max($quantityForBoxes, 0), 12);
                    
                    error_log('PKG-001 deduction check: quantity=' . $quantity . ', additionalQty=' . $additionalPkg001Qty . ', pkg001Id=' . ($pkg001Id ?? 'NULL'));
                    
                    if ($additionalPkg001Qty > 0 && $pkg001Id !== null) {
                            $pkg001Merged = false;
                            foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg001Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg001CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                    $packItem['quantity'] += $additionalPkg001Qty;
                                $packItem['material_code'] = $pkg001DisplayCode;
                                $packItem['material_id'] = $pkg001Id; // تأكيد تعيين material_id
                                    $pkg001Merged = true;
                                error_log('PKG-001 merged with existing item. New quantity=' . $packItem['quantity']);
                                    break;
                                }
                            }
                            unset($packItem);

                            if (!$pkg001Merged) {
                                $pkg001ProductId = ensureProductionMaterialProductId($pkg001Name, 'packaging', $pkg001Unit);

                                $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg001Id, // تأكيد أن material_id ليس null
                                    'quantity' => $additionalPkg001Qty,
                                    'name' => $pkg001Name,
                                    'unit' => $pkg001Unit,
                                    'product_id' => $pkg001ProductId,
                                    'supplier_id' => null,
                                    'template_item_id' => null,
                                'material_code' => $pkg001DisplayCode
                                ];
                            error_log('PKG-001 added as new item. ID=' . $pkg001Id . ', Quantity=' . $additionalPkg001Qty);
                            }

                            $packagingIdsMap[$pkg001Id] = true;
                    } elseif ($additionalPkg001Qty > 0 && $pkg001Id === null) {
                        error_log('PKG-001 automatic deduction skipped: material_id is null. Quantity would have been: ' . $additionalPkg001Qty);
                    }
                }

                if ($templateIncludesPkg004 && $quantity >= 12) {
                    error_log('PKG-001 deduction logic triggered (from PKG-004): templateIncludesPkg004=' . ($templateIncludesPkg004 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-001 عند وجود PKG-004 لكل 12 وحدة منتجة
                    $pkg001CodeKey = 'PKG001';
                    $pkg001DisplayCode = $formatPackagingCode($pkg001CodeKey) ?? 'PKG-001';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg001Id = null;
                    $pkg001Name = 'مادة تعبئة PKG-001';
                    $pkg001Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG001'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG001%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG001%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg001Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg001Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg001Unit = $directSearch['unit'];
                                }
                                error_log('PKG-001 found by direct search (from PKG-004): ID=' . $pkg001Id . ', Name=' . $pkg001Name);
                    } else {
                                // محاولة البحث بالكود
                                $pkg001Info = $fetchPackagingMaterialByCode($pkg001DisplayCode) ?? [];
                                if (!empty($pkg001Info['id'])) {
                                    $pkg001Id = (int)$pkg001Info['id'];
                                    if (!empty($pkg001Info['name'])) {
                                        $pkg001Name = $pkg001Info['name'];
                                    }
                                    if (!empty($pkg001Info['unit'])) {
                                        $pkg001Unit = $pkg001Info['unit'];
                                    }
                                    error_log('PKG-001 found by code search (from PKG-004): ID=' . $pkg001Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg001ByName = $resolvePackagingByName('PKG-001');
                                    if ($pkg001ByName && !empty($pkg001ByName['id'])) {
                                        $pkg001Id = (int)$pkg001ByName['id'];
                                        if (!empty($pkg001ByName['name'])) {
                                            $pkg001Name = $pkg001ByName['name'];
                                        }
                                        if (!empty($pkg001ByName['unit'])) {
                                            $pkg001Unit = $pkg001ByName['unit'];
                                        }
                                        error_log('PKG-001 found by name search (from PKG-004): ID=' . $pkg001Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-001 search error (from PKG-004): ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg001Id === null) {
                        error_log('PKG-001 NOT FOUND in database (from PKG-004). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg001Qty = intdiv(max($quantityForBoxes, 0), 12);  // 1 لكل 12 وحدة
                    
                    error_log('PKG-001 deduction check (from PKG-004): quantity=' . $quantity . ', additionalQty=' . $additionalPkg001Qty . ', pkg001Id=' . ($pkg001Id ?? 'NULL'));
                    
                    if ($additionalPkg001Qty > 0 && $pkg001Id !== null) {
                        $pkg001Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg001Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg001CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg001Qty;
                                $packItem['material_code'] = $pkg001DisplayCode;
                                $packItem['material_id'] = $pkg001Id; // تأكيد تعيين material_id
                                $pkg001Merged = true;
                                error_log('PKG-001 merged with existing item (from PKG-004). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg001Merged) {
                            $pkg001ProductId = ensureProductionMaterialProductId($pkg001Name, 'packaging', $pkg001Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg001Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg001Qty,
                                'name' => $pkg001Name,
                                'unit' => $pkg001Unit,
                                'product_id' => $pkg001ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg001DisplayCode
                            ];
                            error_log('PKG-001 added as new item (from PKG-004). ID=' . $pkg001Id . ', Quantity=' . $additionalPkg001Qty);
                        }

                        $packagingIdsMap[$pkg001Id] = true;
                    } elseif ($additionalPkg001Qty > 0 && $pkg001Id === null) {
                        error_log('PKG-001 automatic deduction skipped (from PKG-004): material_id is null. Quantity would have been: ' . $additionalPkg001Qty);
                    }
                }

                if ($templateIncludesPkg042 && $quantity >= 12) {
                    error_log('PKG-001 deduction logic triggered (from PKG-042): templateIncludesPkg042=' . ($templateIncludesPkg042 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-001 عند وجود PKG-042 لكل 12 وحدة منتجة
                    $pkg001CodeKey = 'PKG001';
                    $pkg001DisplayCode = $formatPackagingCode($pkg001CodeKey) ?? 'PKG-001';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg001Id = null;
                    $pkg001Name = 'مادة تعبئة PKG-001';
                    $pkg001Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG001'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG001%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG001%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg001Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg001Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg001Unit = $directSearch['unit'];
                                }
                                error_log('PKG-001 found by direct search (from PKG-042): ID=' . $pkg001Id . ', Name=' . $pkg001Name);
                            } else {
                                // محاولة البحث بالكود
                                $pkg001Info = $fetchPackagingMaterialByCode($pkg001DisplayCode) ?? [];
                                if (!empty($pkg001Info['id'])) {
                                    $pkg001Id = (int)$pkg001Info['id'];
                                    if (!empty($pkg001Info['name'])) {
                                        $pkg001Name = $pkg001Info['name'];
                                    }
                                    if (!empty($pkg001Info['unit'])) {
                                        $pkg001Unit = $pkg001Info['unit'];
                                    }
                                    error_log('PKG-001 found by code search (from PKG-042): ID=' . $pkg001Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg001ByName = $resolvePackagingByName('PKG-001');
                                    if ($pkg001ByName && !empty($pkg001ByName['id'])) {
                                        $pkg001Id = (int)$pkg001ByName['id'];
                                        if (!empty($pkg001ByName['name'])) {
                                            $pkg001Name = $pkg001ByName['name'];
                                        }
                                        if (!empty($pkg001ByName['unit'])) {
                                            $pkg001Unit = $pkg001ByName['unit'];
                                        }
                                        error_log('PKG-001 found by name search (from PKG-042): ID=' . $pkg001Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-001 search error (from PKG-042): ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg001Id === null) {
                        error_log('PKG-001 NOT FOUND in database (from PKG-042). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg001Qty = intdiv(max($quantityForBoxes, 0), 12);  // 1 لكل 12 وحدة
                    
                    error_log('PKG-001 deduction check (from PKG-042): quantity=' . $quantity . ', additionalQty=' . $additionalPkg001Qty . ', pkg001Id=' . ($pkg001Id ?? 'NULL'));
                    
                    if ($additionalPkg001Qty > 0 && $pkg001Id !== null) {
                        $pkg001Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg001Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg001CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg001Qty;
                                $packItem['material_code'] = $pkg001DisplayCode;
                                $packItem['material_id'] = $pkg001Id; // تأكيد تعيين material_id
                                $pkg001Merged = true;
                                error_log('PKG-001 merged with existing item (from PKG-042). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg001Merged) {
                            $pkg001ProductId = ensureProductionMaterialProductId($pkg001Name, 'packaging', $pkg001Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg001Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg001Qty,
                                'name' => $pkg001Name,
                                'unit' => $pkg001Unit,
                                'product_id' => $pkg001ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg001DisplayCode
                            ];
                            error_log('PKG-001 added as new item (from PKG-042). ID=' . $pkg001Id . ', Quantity=' . $additionalPkg001Qty);
                        }

                        $packagingIdsMap[$pkg001Id] = true;
                    } elseif ($additionalPkg001Qty > 0 && $pkg001Id === null) {
                        error_log('PKG-001 automatic deduction skipped (from PKG-042): material_id is null. Quantity would have been: ' . $additionalPkg001Qty);
                    }
                }

                if ($templateIncludesPkg011 && $quantity >= 12) {
                    error_log('PKG-001 deduction logic triggered (from PKG-011): templateIncludesPkg011=' . ($templateIncludesPkg011 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-001 عند وجود PKG-011 لكل 12 وحدة منتجة
                    $pkg001CodeKey = 'PKG001';
                    $pkg001DisplayCode = $formatPackagingCode($pkg001CodeKey) ?? 'PKG-001';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg001Id = null;
                    $pkg001Name = 'مادة تعبئة PKG-001';
                    $pkg001Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG001'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG001%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG001%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg001Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg001Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg001Unit = $directSearch['unit'];
                                }
                                error_log('PKG-001 found by direct search (from PKG-011): ID=' . $pkg001Id . ', Name=' . $pkg001Name);
                            } else {
                                // محاولة البحث بالكود
                                $pkg001Info = $fetchPackagingMaterialByCode($pkg001DisplayCode) ?? [];
                                if (!empty($pkg001Info['id'])) {
                                    $pkg001Id = (int)$pkg001Info['id'];
                                    if (!empty($pkg001Info['name'])) {
                                        $pkg001Name = $pkg001Info['name'];
                                    }
                                    if (!empty($pkg001Info['unit'])) {
                                        $pkg001Unit = $pkg001Info['unit'];
                                    }
                                    error_log('PKG-001 found by code search (from PKG-011): ID=' . $pkg001Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg001ByName = $resolvePackagingByName('PKG-001');
                                    if ($pkg001ByName && !empty($pkg001ByName['id'])) {
                                        $pkg001Id = (int)$pkg001ByName['id'];
                                        if (!empty($pkg001ByName['name'])) {
                                            $pkg001Name = $pkg001ByName['name'];
                                        }
                                        if (!empty($pkg001ByName['unit'])) {
                                            $pkg001Unit = $pkg001ByName['unit'];
                                        }
                                        error_log('PKG-001 found by name search (from PKG-011): ID=' . $pkg001Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-001 search error (from PKG-011): ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg001Id === null) {
                        error_log('PKG-001 NOT FOUND in database (from PKG-011). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg001Qty = intdiv(max($quantityForBoxes, 0), 12);  // 1 لكل 12 وحدة
                    
                    error_log('PKG-001 deduction check (from PKG-011): quantity=' . $quantity . ', additionalQty=' . $additionalPkg001Qty . ', pkg001Id=' . ($pkg001Id ?? 'NULL'));
                    
                    if ($additionalPkg001Qty > 0 && $pkg001Id !== null) {
                        $pkg001Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg001Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg001CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg001Qty;
                                $packItem['material_code'] = $pkg001DisplayCode;
                                $packItem['material_id'] = $pkg001Id; // تأكيد تعيين material_id
                                $pkg001Merged = true;
                                error_log('PKG-001 merged with existing item (from PKG-011). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg001Merged) {
                            $pkg001ProductId = ensureProductionMaterialProductId($pkg001Name, 'packaging', $pkg001Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg001Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg001Qty,
                                'name' => $pkg001Name,
                                'unit' => $pkg001Unit,
                                'product_id' => $pkg001ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg001DisplayCode
                            ];
                            error_log('PKG-001 added as new item (from PKG-011). ID=' . $pkg001Id . ', Quantity=' . $additionalPkg001Qty);
                        }

                        $packagingIdsMap[$pkg001Id] = true;
                    } elseif ($additionalPkg001Qty > 0 && $pkg001Id === null) {
                        error_log('PKG-001 automatic deduction skipped (from PKG-011): material_id is null. Quantity would have been: ' . $additionalPkg001Qty);
                    }
                }

                if ($templateIncludesPkg010 && $quantity >= 6) {
                    error_log('PKG-002 deduction logic triggered: templateIncludesPkg010=' . ($templateIncludesPkg010 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-002 عند وجود PKG-010 لكل 6 وحدة منتجة
                    $pkg002CodeKey = 'PKG002';
                    $pkg002DisplayCode = $formatPackagingCode($pkg002CodeKey) ?? 'PKG-002';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg002Id = null;
                    $pkg002Name = 'مادة تعبئة PKG-002';
                    $pkg002Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG002'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG002%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG002%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg002Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg002Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg002Unit = $directSearch['unit'];
                                }
                                error_log('PKG-002 found by direct search: ID=' . $pkg002Id . ', Name=' . $pkg002Name);
                    } else {
                                // محاولة البحث بالكود
                                $pkg002Info = $fetchPackagingMaterialByCode($pkg002DisplayCode) ?? [];
                                if (!empty($pkg002Info['id'])) {
                                    $pkg002Id = (int)$pkg002Info['id'];
                                    if (!empty($pkg002Info['name'])) {
                                        $pkg002Name = $pkg002Info['name'];
                                    }
                                    if (!empty($pkg002Info['unit'])) {
                                        $pkg002Unit = $pkg002Info['unit'];
                                    }
                                    error_log('PKG-002 found by code search: ID=' . $pkg002Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg002ByName = $resolvePackagingByName('PKG-002');
                                    if ($pkg002ByName && !empty($pkg002ByName['id'])) {
                                        $pkg002Id = (int)$pkg002ByName['id'];
                                        if (!empty($pkg002ByName['name'])) {
                                            $pkg002Name = $pkg002ByName['name'];
                                        }
                                        if (!empty($pkg002ByName['unit'])) {
                                            $pkg002Unit = $pkg002ByName['unit'];
                                        }
                                        error_log('PKG-002 found by name search: ID=' . $pkg002Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-002 search error: ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg002Id === null) {
                        error_log('PKG-002 NOT FOUND in database. Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg002Qty = intdiv(max($quantityForBoxes, 0), 6);  // 1 لكل 6 وحدة
                    
                    error_log('PKG-002 deduction check: quantity=' . $quantity . ', additionalQty=' . $additionalPkg002Qty . ', pkg002Id=' . ($pkg002Id ?? 'NULL'));
                    
                    if ($additionalPkg002Qty > 0 && $pkg002Id !== null) {
                        $pkg002Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg002Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg002CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg002Qty;
                                $packItem['material_code'] = $pkg002DisplayCode;
                                $packItem['material_id'] = $pkg002Id; // تأكيد تعيين material_id
                                $pkg002Merged = true;
                                error_log('PKG-002 merged with existing item. New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg002Merged) {
                            $pkg002ProductId = ensureProductionMaterialProductId($pkg002Name, 'packaging', $pkg002Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg002Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg002Qty,
                                'name' => $pkg002Name,
                                'unit' => $pkg002Unit,
                                'product_id' => $pkg002ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg002DisplayCode
                            ];
                            error_log('PKG-002 added as new item. ID=' . $pkg002Id . ', Quantity=' . $additionalPkg002Qty);
                        }

                        $packagingIdsMap[$pkg002Id] = true;
                    } elseif ($additionalPkg002Qty > 0 && $pkg002Id === null) {
                        error_log('PKG-002 automatic deduction skipped: material_id is null. Quantity would have been: ' . $additionalPkg002Qty);
                    }
                }

                if ($templateIncludesPkg036 && $quantity >= 6) {
                    error_log('PKG-002 deduction logic triggered (from PKG-036): templateIncludesPkg036=' . ($templateIncludesPkg036 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-002 عند وجود PKG-036 لكل 6 وحدة منتجة
                    $pkg002CodeKey = 'PKG002';
                    $pkg002DisplayCode = $formatPackagingCode($pkg002CodeKey) ?? 'PKG-002';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg002Id = null;
                    $pkg002Name = 'مادة تعبئة PKG-002';
                    $pkg002Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG002'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG002%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG002%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg002Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg002Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg002Unit = $directSearch['unit'];
                                }
                                error_log('PKG-002 found by direct search (from PKG-036): ID=' . $pkg002Id . ', Name=' . $pkg002Name);
                            } else {
                                // محاولة البحث بالكود
                                $pkg002Info = $fetchPackagingMaterialByCode($pkg002DisplayCode) ?? [];
                                if (!empty($pkg002Info['id'])) {
                                    $pkg002Id = (int)$pkg002Info['id'];
                                    if (!empty($pkg002Info['name'])) {
                                        $pkg002Name = $pkg002Info['name'];
                                    }
                                    if (!empty($pkg002Info['unit'])) {
                                        $pkg002Unit = $pkg002Info['unit'];
                                    }
                                    error_log('PKG-002 found by code search (from PKG-036): ID=' . $pkg002Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg002ByName = $resolvePackagingByName('PKG-002');
                                    if ($pkg002ByName && !empty($pkg002ByName['id'])) {
                                        $pkg002Id = (int)$pkg002ByName['id'];
                                        if (!empty($pkg002ByName['name'])) {
                                            $pkg002Name = $pkg002ByName['name'];
                                        }
                                        if (!empty($pkg002ByName['unit'])) {
                                            $pkg002Unit = $pkg002ByName['unit'];
                                        }
                                        error_log('PKG-002 found by name search (from PKG-036): ID=' . $pkg002Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-002 search error (from PKG-036): ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg002Id === null) {
                        error_log('PKG-002 NOT FOUND in database (from PKG-036). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg002Qty = intdiv(max($quantityForBoxes, 0), 6);  // 1 لكل 6 وحدة
                    
                    error_log('PKG-002 deduction check (from PKG-036): quantity=' . $quantity . ', additionalQty=' . $additionalPkg002Qty . ', pkg002Id=' . ($pkg002Id ?? 'NULL'));
                    
                    if ($additionalPkg002Qty > 0 && $pkg002Id !== null) {
                        $pkg002Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg002Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg002CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg002Qty;
                                $packItem['material_code'] = $pkg002DisplayCode;
                                $packItem['material_id'] = $pkg002Id; // تأكيد تعيين material_id
                                $pkg002Merged = true;
                                error_log('PKG-002 merged with existing item (from PKG-036). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg002Merged) {
                            $pkg002ProductId = ensureProductionMaterialProductId($pkg002Name, 'packaging', $pkg002Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg002Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg002Qty,
                                'name' => $pkg002Name,
                                'unit' => $pkg002Unit,
                                'product_id' => $pkg002ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg002DisplayCode
                            ];
                            error_log('PKG-002 added as new item (from PKG-036). ID=' . $pkg002Id . ', Quantity=' . $additionalPkg002Qty);
                        }

                        $packagingIdsMap[$pkg002Id] = true;
                    } elseif ($additionalPkg002Qty > 0 && $pkg002Id === null) {
                        error_log('PKG-002 automatic deduction skipped (from PKG-036): material_id is null. Quantity would have been: ' . $additionalPkg002Qty);
                    }
                }

                if ($templateIncludesPkg012 && $quantity >= 24) {
                    error_log('PKG-041 deduction logic triggered (from PKG-012): templateIncludesPkg012=' . ($templateIncludesPkg012 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-041 عند وجود PKG-012 لكل 24 وحدة منتجة
                    $pkg041CodeKey = 'PKG041';
                    $pkg041DisplayCode = $formatPackagingCode($pkg041CodeKey) ?? 'PKG-041';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg041Id = null;
                    $pkg041Name = 'مادة تعبئة PKG-041';
                    $pkg041Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG041'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG041%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG041%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg041Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg041Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg041Unit = $directSearch['unit'];
                                }
                                error_log('PKG-041 found by direct search (from PKG-012): ID=' . $pkg041Id . ', Name=' . $pkg041Name);
                            } else {
                                // محاولة البحث بالكود
                                $pkg041Info = $fetchPackagingMaterialByCode($pkg041DisplayCode) ?? [];
                                if (!empty($pkg041Info['id'])) {
                                    $pkg041Id = (int)$pkg041Info['id'];
                                    if (!empty($pkg041Info['name'])) {
                                        $pkg041Name = $pkg041Info['name'];
                                    }
                                    if (!empty($pkg041Info['unit'])) {
                                        $pkg041Unit = $pkg041Info['unit'];
                                    }
                                    error_log('PKG-041 found by code search (from PKG-012): ID=' . $pkg041Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg041ByName = $resolvePackagingByName('PKG-041');
                                    if ($pkg041ByName && !empty($pkg041ByName['id'])) {
                                        $pkg041Id = (int)$pkg041ByName['id'];
                                        if (!empty($pkg041ByName['name'])) {
                                            $pkg041Name = $pkg041ByName['name'];
                                        }
                                        if (!empty($pkg041ByName['unit'])) {
                                            $pkg041Unit = $pkg041ByName['unit'];
                                        }
                                        error_log('PKG-041 found by name search (from PKG-012): ID=' . $pkg041Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-041 search error (from PKG-012): ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg041Id === null) {
                        error_log('PKG-041 NOT FOUND in database (from PKG-012). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg041Qty = intdiv(max($quantityForBoxes, 0), 24);  // 1 لكل 24 وحدة
                    
                    error_log('PKG-041 deduction check (from PKG-012): quantity=' . $quantity . ', additionalQty=' . $additionalPkg041Qty . ', pkg041Id=' . ($pkg041Id ?? 'NULL'));
                    
                    if ($additionalPkg041Qty > 0 && $pkg041Id !== null) {
                        $pkg041Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg041Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg041CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg041Qty;
                                $packItem['material_code'] = $pkg041DisplayCode;
                                $packItem['material_id'] = $pkg041Id; // تأكيد تعيين material_id
                                $pkg041Merged = true;
                                error_log('PKG-041 merged with existing item (from PKG-012). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg041Merged) {
                            $pkg041ProductId = ensureProductionMaterialProductId($pkg041Name, 'packaging', $pkg041Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg041Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg041Qty,
                                'name' => $pkg041Name,
                                'unit' => $pkg041Unit,
                                'product_id' => $pkg041ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg041DisplayCode
                            ];
                            error_log('PKG-041 added as new item (from PKG-012). ID=' . $pkg041Id . ', Quantity=' . $additionalPkg041Qty);
                        }

                        $packagingIdsMap[$pkg041Id] = true;
                    } elseif ($additionalPkg041Qty > 0 && $pkg041Id === null) {
                        error_log('PKG-041 automatic deduction skipped (from PKG-012): material_id is null. Quantity would have been: ' . $additionalPkg041Qty);
                    }
                }

                if ($templateIncludesPkg007 && $quantity >= 24) {
                    error_log('PKG-041 deduction logic triggered: templateIncludesPkg007=' . ($templateIncludesPkg007 ? 'true' : 'false') . ', quantity=' . $quantity);
                    $pkg041CodeKey = 'PKG041';
                    $pkg041DisplayCode = $formatPackagingCode($pkg041CodeKey) ?? 'PKG-041';

                    $pkg041Id = null;
                    $pkg041Name = 'مادة تعبئة PKG-041';
                    $pkg041Unit = 'قطعة';

                    if ($packagingTableExists) {
                        try {
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG041'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG041%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG041%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg041Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg041Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg041Unit = $directSearch['unit'];
                                }
                                error_log('PKG-041 found by direct search: ID=' . $pkg041Id . ', Name=' . $pkg041Name);
                            } else {
                                $pkg041Info = $fetchPackagingMaterialByCode($pkg041DisplayCode) ?? [];
                                if (!empty($pkg041Info['id'])) {
                                    $pkg041Id = (int)$pkg041Info['id'];
                                    if (!empty($pkg041Info['name'])) {
                                        $pkg041Name = $pkg041Info['name'];
                                    }
                                    if (!empty($pkg041Info['unit'])) {
                                        $pkg041Unit = $pkg041Info['unit'];
                                    }
                                    error_log('PKG-041 found by code search: ID=' . $pkg041Id);
                                } else {
                                    $pkg041ByName = $resolvePackagingByName('PKG-041');
                                    if ($pkg041ByName && !empty($pkg041ByName['id'])) {
                                        $pkg041Id = (int)$pkg041ByName['id'];
                                        if (!empty($pkg041ByName['name'])) {
                                            $pkg041Name = $pkg041ByName['name'];
                                        }
                                        if (!empty($pkg041ByName['unit'])) {
                                            $pkg041Unit = $pkg041ByName['unit'];
                                        }
                                        error_log('PKG-041 found by name search: ID=' . $pkg041Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-041 search error: ' . $searchError->getMessage());
                        }
                    }

                    if ($pkg041Id === null) {
                        error_log('PKG-041 NOT FOUND in database. Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg041Qty = intdiv(max($quantityForBoxes, 0), 24);

                    error_log('PKG-041 deduction check: quantity=' . $quantity . ', additionalQty=' . $additionalPkg041Qty . ', pkg041Id=' . ($pkg041Id ?? 'NULL'));

                    if ($additionalPkg041Qty > 0 && $pkg041Id !== null) {
                        $pkg041Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg041Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg041CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg041Qty;
                                $packItem['material_code'] = $pkg041DisplayCode;
                                $packItem['material_id'] = $pkg041Id;
                                $pkg041Merged = true;
                                error_log('PKG-041 merged with existing item. New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg041Merged) {
                            $pkg041ProductId = ensureProductionMaterialProductId($pkg041Name, 'packaging', $pkg041Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg041Id,
                                'quantity' => $additionalPkg041Qty,
                                'name' => $pkg041Name,
                                'unit' => $pkg041Unit,
                                'product_id' => $pkg041ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg041DisplayCode
                            ];
                            error_log('PKG-041 added as new item. ID=' . $pkg041Id . ', Quantity=' . $additionalPkg041Qty);
                        }

                        $packagingIdsMap[$pkg041Id] = true;
                    } elseif ($additionalPkg041Qty > 0 && $pkg041Id === null) {
                        error_log('PKG-041 automatic deduction skipped: material_id is null. Quantity would have been: ' . $additionalPkg041Qty);
                    }
                }

                if ($templateIncludesPkg005 && $quantity >= 18) {
                    error_log('PKG-041 deduction logic triggered (from PKG-005): templateIncludesPkg005=' . ($templateIncludesPkg005 ? 'true' : 'false') . ', quantity=' . $quantity);
                    $pkg041CodeKey = 'PKG041';
                    $pkg041DisplayCode = $formatPackagingCode($pkg041CodeKey) ?? 'PKG-041';

                    $pkg041Id = null;
                    $pkg041Name = 'مادة تعبئة PKG-041';
                    $pkg041Unit = 'قطعة';

                    if ($packagingTableExists) {
                        try {
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG041'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG041%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG041%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg041Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg041Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg041Unit = $directSearch['unit'];
                                }
                                error_log('PKG-041 found by direct search (from PKG-005): ID=' . $pkg041Id . ', Name=' . $pkg041Name);
                            } else {
                                $pkg041Info = $fetchPackagingMaterialByCode($pkg041DisplayCode) ?? [];
                                if (!empty($pkg041Info['id'])) {
                                    $pkg041Id = (int)$pkg041Info['id'];
                                    if (!empty($pkg041Info['name'])) {
                                        $pkg041Name = $pkg041Info['name'];
                                    }
                                    if (!empty($pkg041Info['unit'])) {
                                        $pkg041Unit = $pkg041Info['unit'];
                                    }
                                    error_log('PKG-041 found by code search (from PKG-005): ID=' . $pkg041Id);
                                } else {
                                    $pkg041ByName = $resolvePackagingByName('PKG-041');
                                    if ($pkg041ByName && !empty($pkg041ByName['id'])) {
                                        $pkg041Id = (int)$pkg041ByName['id'];
                                        if (!empty($pkg041ByName['name'])) {
                                            $pkg041Name = $pkg041ByName['name'];
                                        }
                                        if (!empty($pkg041ByName['unit'])) {
                                            $pkg041Unit = $pkg041ByName['unit'];
                                        }
                                        error_log('PKG-041 found by name search (from PKG-005): ID=' . $pkg041Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-041 search error (from PKG-005): ' . $searchError->getMessage());
                        }
                    }

                    if ($pkg041Id === null) {
                        error_log('PKG-041 NOT FOUND in database (from PKG-005). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg041Qty = intdiv(max($quantityForBoxes, 0), 18);

                    error_log('PKG-041 deduction check (from PKG-005): quantity=' . $quantity . ', additionalQty=' . $additionalPkg041Qty . ', pkg041Id=' . ($pkg041Id ?? 'NULL'));

                    if ($additionalPkg041Qty > 0 && $pkg041Id !== null) {
                        $pkg041Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg041Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg041CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg041Qty;
                                $packItem['material_code'] = $pkg041DisplayCode;
                                $packItem['material_id'] = $pkg041Id;
                                $pkg041Merged = true;
                                error_log('PKG-041 merged with existing item (from PKG-005). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg041Merged) {
                            $pkg041ProductId = ensureProductionMaterialProductId($pkg041Name, 'packaging', $pkg041Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg041Id,
                                'quantity' => $additionalPkg041Qty,
                                'name' => $pkg041Name,
                                'unit' => $pkg041Unit,
                                'product_id' => $pkg041ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg041DisplayCode
                            ];
                            error_log('PKG-041 added as new item (from PKG-005). ID=' . $pkg041Id . ', Quantity=' . $additionalPkg041Qty);
                        }

                        $packagingIdsMap[$pkg041Id] = true;
                    } elseif ($additionalPkg041Qty > 0 && $pkg041Id === null) {
                        error_log('PKG-041 automatic deduction skipped (from PKG-005): material_id is null. Quantity would have been: ' . $additionalPkg041Qty);
                    }
                }

                if ($templateIncludesPkg006 && $quantity >= 18) {
                    error_log('PKG-041 deduction logic triggered (from PKG-006): templateIncludesPkg006=' . ($templateIncludesPkg006 ? 'true' : 'false') . ', quantity=' . $quantity);
                    $pkg041CodeKey = 'PKG041';
                    $pkg041DisplayCode = $formatPackagingCode($pkg041CodeKey) ?? 'PKG-041';

                    $pkg041Id = null;
                    $pkg041Name = 'مادة تعبئة PKG-041';
                    $pkg041Unit = 'قطعة';

                    if ($packagingTableExists) {
                        try {
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG041'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG041%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG041%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg041Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg041Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg041Unit = $directSearch['unit'];
                                }
                                error_log('PKG-041 found by direct search (from PKG-006): ID=' . $pkg041Id . ', Name=' . $pkg041Name);
                            } else {
                                $pkg041Info = $fetchPackagingMaterialByCode($pkg041DisplayCode) ?? [];
                                if (!empty($pkg041Info['id'])) {
                                    $pkg041Id = (int)$pkg041Info['id'];
                                    if (!empty($pkg041Info['name'])) {
                                        $pkg041Name = $pkg041Info['name'];
                                    }
                                    if (!empty($pkg041Info['unit'])) {
                                        $pkg041Unit = $pkg041Info['unit'];
                                    }
                                    error_log('PKG-041 found by code search (from PKG-006): ID=' . $pkg041Id);
                                } else {
                                    $pkg041ByName = $resolvePackagingByName('PKG-041');
                                    if ($pkg041ByName && !empty($pkg041ByName['id'])) {
                                        $pkg041Id = (int)$pkg041ByName['id'];
                                        if (!empty($pkg041ByName['name'])) {
                                            $pkg041Name = $pkg041ByName['name'];
                                        }
                                        if (!empty($pkg041ByName['unit'])) {
                                            $pkg041Unit = $pkg041ByName['unit'];
                                        }
                                        error_log('PKG-041 found by name search (from PKG-006): ID=' . $pkg041Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-041 search error (from PKG-006): ' . $searchError->getMessage());
                        }
                    }

                    if ($pkg041Id === null) {
                        error_log('PKG-041 NOT FOUND in database (from PKG-006). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg041Qty = intdiv(max($quantityForBoxes, 0), 18);

                    error_log('PKG-041 deduction check (from PKG-006): quantity=' . $quantity . ', additionalQty=' . $additionalPkg041Qty . ', pkg041Id=' . ($pkg041Id ?? 'NULL'));

                    if ($additionalPkg041Qty > 0 && $pkg041Id !== null) {
                        $pkg041Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg041Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg041CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg041Qty;
                                $packItem['material_code'] = $pkg041DisplayCode;
                                $packItem['material_id'] = $pkg041Id;
                                $pkg041Merged = true;
                                error_log('PKG-041 merged with existing item (from PKG-006). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg041Merged) {
                            $pkg041ProductId = ensureProductionMaterialProductId($pkg041Name, 'packaging', $pkg041Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg041Id,
                                'quantity' => $additionalPkg041Qty,
                                'name' => $pkg041Name,
                                'unit' => $pkg041Unit,
                                'product_id' => $pkg041ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg041DisplayCode
                            ];
                            error_log('PKG-041 added as new item (from PKG-006). ID=' . $pkg041Id . ', Quantity=' . $additionalPkg041Qty);
                        }

                        $packagingIdsMap[$pkg041Id] = true;
                    } elseif ($additionalPkg041Qty > 0 && $pkg041Id === null) {
                        error_log('PKG-041 automatic deduction skipped (from PKG-006): material_id is null. Quantity would have been: ' . $additionalPkg041Qty);
                    }
                }

                if ($templateIncludesPkg003 && $quantity >= 24) {
                    error_log('PKG-002 deduction logic triggered (from PKG-003): templateIncludesPkg003=' . ($templateIncludesPkg003 ? 'true' : 'false') . ', quantity=' . $quantity);
                    // خصم تلقائي لـ PKG-002 عند وجود PKG-003 لكل 24 وحدة منتجة
                    $pkg002CodeKey = 'PKG002';
                    $pkg002DisplayCode = $formatPackagingCode($pkg002CodeKey) ?? 'PKG-002';
                    
                    // البحث المباشر في قاعدة البيانات أولاً - الحل الأكثر موثوقية
                    $pkg002Id = null;
                    $pkg002Name = 'مادة تعبئة PKG-002';
                    $pkg002Unit = 'قطعة';
                    
                    if ($packagingTableExists) {
                        try {
                            // البحث المباشر - الأكثر موثوقية
                            $directSearch = $db->queryOne(
                                "SELECT id, name, unit, material_id FROM packaging_materials 
                                 WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = 'PKG002'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE 'PKG002%'
                                    OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE '%PKG002%'
                                 LIMIT 1"
                            );
                            if ($directSearch && !empty($directSearch['id'])) {
                                $pkg002Id = (int)$directSearch['id'];
                                if (!empty($directSearch['name'])) {
                                    $pkg002Name = $directSearch['name'];
                                }
                                if (!empty($directSearch['unit'])) {
                                    $pkg002Unit = $directSearch['unit'];
                                }
                                error_log('PKG-002 found by direct search (from PKG-003): ID=' . $pkg002Id . ', Name=' . $pkg002Name);
                            } else {
                                // محاولة البحث بالكود
                                $pkg002Info = $fetchPackagingMaterialByCode($pkg002DisplayCode) ?? [];
                                if (!empty($pkg002Info['id'])) {
                                    $pkg002Id = (int)$pkg002Info['id'];
                                    if (!empty($pkg002Info['name'])) {
                                        $pkg002Name = $pkg002Info['name'];
                                    }
                                    if (!empty($pkg002Info['unit'])) {
                                        $pkg002Unit = $pkg002Info['unit'];
                                    }
                                    error_log('PKG-002 found by code search (from PKG-003): ID=' . $pkg002Id);
                                } else {
                                    // محاولة البحث بالاسم
                                    $pkg002ByName = $resolvePackagingByName('PKG-002');
                                    if ($pkg002ByName && !empty($pkg002ByName['id'])) {
                                        $pkg002Id = (int)$pkg002ByName['id'];
                                        if (!empty($pkg002ByName['name'])) {
                                            $pkg002Name = $pkg002ByName['name'];
                                        }
                                        if (!empty($pkg002ByName['unit'])) {
                                            $pkg002Unit = $pkg002ByName['unit'];
                                        }
                                        error_log('PKG-002 found by name search (from PKG-003): ID=' . $pkg002Id);
                                    }
                                }
                            }
                        } catch (Throwable $searchError) {
                            error_log('PKG-002 search error (from PKG-003): ' . $searchError->getMessage());
                        }
                    }
                    
                    if ($pkg002Id === null) {
                        error_log('PKG-002 NOT FOUND in database (from PKG-003). Automatic deduction will be skipped.');
                    }

                    $quantityForBoxes = (int)floor((float)$quantity);
                    $additionalPkg002Qty = intdiv(max($quantityForBoxes, 0), 24);  // 1 لكل 24 وحدة
                    
                    error_log('PKG-002 deduction check (from PKG-003): quantity=' . $quantity . ', additionalQty=' . $additionalPkg002Qty . ', pkg002Id=' . ($pkg002Id ?? 'NULL'));
                    
                    if ($additionalPkg002Qty > 0 && $pkg002Id !== null) {
                        $pkg002Merged = false;
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                            $materialIdMatches = isset($packItem['material_id'])
                                && (int)$packItem['material_id'] === $pkg002Id;
                            $packItemCodeKey = isset($packItem['material_code'])
                                ? $normalizePackagingCodeKey($packItem['material_code'])
                                : null;
                            $materialCodeMatches = $packItemCodeKey !== null && $packItemCodeKey === $pkg002CodeKey;

                            if ($materialIdMatches || $materialCodeMatches) {
                                $packItem['quantity'] += $additionalPkg002Qty;
                                $packItem['material_code'] = $pkg002DisplayCode;
                                $packItem['material_id'] = $pkg002Id; // تأكيد تعيين material_id
                                $pkg002Merged = true;
                                error_log('PKG-002 merged with existing item (from PKG-003). New quantity=' . $packItem['quantity']);
                                break;
                            }
                        }
                        unset($packItem);

                        if (!$pkg002Merged) {
                            $pkg002ProductId = ensureProductionMaterialProductId($pkg002Name, 'packaging', $pkg002Unit);

                            $materialsConsumption['packaging'][] = [
                                'material_id' => $pkg002Id, // تأكيد أن material_id ليس null
                                'quantity' => $additionalPkg002Qty,
                                'name' => $pkg002Name,
                                'unit' => $pkg002Unit,
                                'product_id' => $pkg002ProductId,
                                'supplier_id' => null,
                                'template_item_id' => null,
                                'material_code' => $pkg002DisplayCode
                            ];
                            error_log('PKG-002 added as new item (from PKG-003). ID=' . $pkg002Id . ', Quantity=' . $additionalPkg002Qty);
                        }

                        $packagingIdsMap[$pkg002Id] = true;
                    } elseif ($additionalPkg002Qty > 0 && $pkg002Id === null) {
                        error_log('PKG-002 automatic deduction skipped (from PKG-003): material_id is null. Quantity would have been: ' . $additionalPkg002Qty);
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

                    // يجب التحقق من الشمع أولاً لتجنب تعارضه مع العسل (مثل "شمع عسل")
                    $isBeeswaxName = (mb_stripos($rawName, 'شمع') !== false) || (stripos($rawName, 'beeswax') !== false) || (stripos($rawName, 'wax') !== false);
                    $isHoneyName = !$isBeeswaxName && ((mb_stripos($rawName, 'عسل') !== false) || (stripos($rawName, 'honey') !== false));
                    $isTahiniName = (mb_stripos($rawName, 'طحينة') !== false) || (stripos($rawName, 'tahini') !== false);
                    if (!in_array($materialType, ['honey_raw', 'honey_filtered', 'olive_oil', 'beeswax', 'derivatives', 'nuts', 'tahini'], true)) {
                        if ($isBeeswaxName) {
                            $materialType = 'beeswax';
                        } elseif ($isHoneyName) {
                            $hasRawKeyword = (mb_stripos($rawName, 'خام') !== false) || (stripos($rawName, 'raw') !== false);
                            $hasFilteredKeyword = (mb_stripos($rawName, 'مصفى') !== false) || (stripos($rawName, 'filtered') !== false);
                            if ($hasRawKeyword && !$hasFilteredKeyword) {
                                $materialType = 'honey_raw';
                            } elseif ($hasFilteredKeyword && !$hasRawKeyword) {
                                $materialType = 'honey_filtered';
                            } else {
                                $materialType = 'honey_filtered';
                            }
                        } elseif ($isTahiniName) {
                            $materialType = 'tahini';
                        } else {
                            $materialType = 'raw_general';
                        }
                    }

                    $selectedHoneyVariety = trim((string)($materialHoneyVarieties[$rawKey] ?? ''));
                    if ($selectedHoneyVariety === '' && $detailEntry && !empty($detailEntry['honey_variety'])) {
                        $selectedHoneyVariety = trim((string)$detailEntry['honey_variety']);
                    }
                    
                    // الحصول على حالة العسل (خام/مصفى) من النموذج
                    $selectedHoneyState = trim((string)($materialHoneyStatesInput[$rawKey] ?? ''));
                    if (empty($selectedHoneyState) && is_array($detailEntry)) {
                        // إذا لم تكن الحالة محددة في النموذج، استخدم material_type
                        $detailType = $detailEntry['material_type'] ?? '';
                        if ($detailType === 'honey_raw') {
                            $selectedHoneyState = 'raw';
                        } elseif ($detailType === 'honey_filtered') {
                            $selectedHoneyState = 'filtered';
                        }
                    }

                    // يجب استثناء مكونات الشمع من معالجة العسل
                    if (!$isBeeswaxName && (in_array($materialType, ['honey_raw', 'honey_filtered', 'honey', 'honey_general', 'honey_main'], true) || $isHoneyName)) {
                        // تحديث material_type بناءً على حالة العسل المختارة
                        if ($selectedHoneyState === 'raw') {
                            $materialType = 'honey_raw';
                        } elseif ($selectedHoneyState === 'filtered') {
                            $materialType = 'honey_filtered';
                        } elseif (!in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                            // إذا لم يتم تحديد الحالة، استخدم المصفى كقيمة افتراضية
                            $materialType = 'honey_filtered';
                        }
                        
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
                        // تحديث السعر من القالب إذا كان المنتج موجوداً ولكن السعر غير محدد أو صفر
                        $existingProductPrice = isset($existingProduct['unit_price']) ? (float)$existingProduct['unit_price'] : 0.0;
                        $templatePrice = isset($template['unit_price']) ? (float)$template['unit_price'] : null;
                        if (($existingProductPrice <= 0 || $existingProductPrice === 0.0) && $templatePrice !== null && $templatePrice > 0 && $templatePrice <= 10000) {
                            $db->execute(
                                "UPDATE products SET unit_price = ? WHERE id = ?",
                                [$templatePrice, $productId]
                            );
                        }
                    } else {
                        $insertProductName = trim((string)$template['product_name']);
                        if ($insertProductName === '') {
                            throw new Exception('يجب تحديد اسم المنتج الحقيقي في قالب الإنتاج قبل إنشاء المنتج.');
                        }
                        // جلب السعر من القالب
                        $templatePrice = isset($template['unit_price']) ? (float)$template['unit_price'] : null;
                        if ($templatePrice !== null && $templatePrice > 0 && $templatePrice <= 10000) {
                            // إنشاء منتج جديد مع السعر
                            $result = $db->execute(
                                "INSERT INTO products (name, category, status, unit, unit_price) VALUES (?, 'finished', 'active', 'قطعة', ?)",
                                [$insertProductName, $templatePrice]
                            );
                        } else {
                            // إنشاء منتج جديد بدون سعر
                            $result = $db->execute(
                                "INSERT INTO products (name, category, status, unit) VALUES (?, 'finished', 'active', 'قطعة')",
                                [$insertProductName]
                            );
                        }
                        $productId = $result['insert_id'];
                    }
                }
                // التحقق من توافر نوع العسل لدى المورد المحدد
                $honeyStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'honey_stock'");
                foreach ($materialsConsumption['raw'] as $rawItem) {
                    $materialType = $rawItem['material_type'] ?? '';
                    $materialName = $rawItem['material_name'] ?? '';
                    
                    // يجب استثناء مكونات الشمع من التحقق من مخزون العسل
                    $isBeeswax = $materialType === 'beeswax' 
                        || (mb_stripos($materialName, 'شمع') !== false) 
                        || (stripos($materialName, 'beeswax') !== false) 
                        || (stripos($materialName, 'wax') !== false);
                    
                    if ($isBeeswax || !in_array($materialType, ['honey_raw', 'honey_filtered'], true)) {
                        continue;
                    }
                    
                    // استثناء "شمع عسل" من التحقق من مخزون العسل
                    $honeyVariety = $rawItem['honey_variety'] ?? '';
                    if (!empty($honeyVariety) && (mb_stripos($honeyVariety, 'شمع') !== false)) {
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
                
                // 3. جمع جميع عمال الإنتاج الحاضرين خلال اليوم لربطهم ببيانات رقم التشغيلة
                // ملاحظة: تسجيل الحضور منفصل لكل حساب، لكن عند إنشاء التشغيلة نجمع جميع العمال الحاضرين
                $workersList = [];
                $attendanceTableCheck = $db->queryOne("SHOW TABLES LIKE 'attendance_records'");
                
                if (!empty($attendanceTableCheck)) {
                    // التحقق من أن المستخدم الحالي قد سجل حضور (ما عدا المدير)
                    if ($currentUser['role'] !== 'manager') {
                        $userAttendanceCheck = $db->queryOne(
                            "SELECT id 
                             FROM attendance_records 
                             WHERE user_id = ? 
                             AND date = ? 
                             AND check_in_time IS NOT NULL 
                             LIMIT 1",
                            [$selectedUserId, $productionDate]
                        );
                        
                        // إذا لم يسجل المستخدم حضور، إظهار رسالة خطأ وإيقاف المعالجة
                        if (empty($userAttendanceCheck)) {
                            $error = 'يجب تسجيل الحضور أولاً قبل إضافة عملية إنتاج. يرجى تسجيل الحضور من صفحة الحضور والانصراف.';
                            preventDuplicateSubmission(null, ['page' => 'production'], null, $currentUser['role'], $error);
                        }
                    }
                    
                    // جمع جميع عمال الإنتاج الحاضرين خلال اليوم لربطهم ببيانات رقم التشغيلة
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
                        $workerId = intval($worker['user_id']);
                        if ($workerId > 0 && !in_array($workerId, $workersList, true)) {
                            $workersList[] = $workerId;
                        }
                    }
                }
                
                // إذا كان المستخدم مدير، أضفه مباشرة (المدير لا يحتاج لتسجيل حضور)
                if ($currentUser['role'] === 'manager') {
                    if (!in_array($selectedUserId, $workersList, true)) {
                        $workersList[] = $selectedUserId;
                    }
                }
                
                // التأكد من وجود عامل واحد على الأقل
                if (empty($workersList)) {
                    $workersList[] = $selectedUserId;
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
                            case 'tahini':
                                if ($supplierForDeduction) {
                                    // خصم من tahini_stock باستخدام supplier_id من السمسم
                                    $db->execute(
                                        "UPDATE tahini_stock 
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
                    } catch (Exception $stockWarning) {
                        error_log('Production stock deduction warning: ' . $stockWarning->getMessage());
                    }
                }

                // خصم مواد التعبئة
                // ملاحظة: إذا كان stock_deducted = true، فهذا يعني أن batchCreationCreate قد قام بالخصم بالفعل
                // لذلك نتخطى الخصم هنا تماماً لتجنب الخصم المزدوج
                
                // التحقق أولاً: إذا كان batchCreationCreate قد قام بالخصم، نتخطى كامل كتلة خصم مواد التعبئة
                if (!empty($batchResult['stock_deducted'])) {
                    error_log('Skipping packaging deduction - already deducted by batchCreationCreate (stock_deducted=true)');
                    // batchCreationCreate قام بالخصم بالفعل، لا نكرر الخصم ولا ننفذ أي معالجة لمواد التعبئة
                } else {
                    // فقط عند عدم قيام batchCreationCreate بالخصم، ننفذ خصم مواد التعبئة هنا
                    try {
                        // تتبع العناصر التي تم خصمها بالفعل لتجنب الخصم المكرر
                        // استخدام static لتتبع عبر جميع عمليات الإنتاج في نفس الطلب
                        static $globalDeductedPackagingIds = [];
                        $deductedPackagingIds = &$globalDeductedPackagingIds;
                        
                        // إعادة تعيين فقط عند بداية معاملة جديدة (عند عدم وجود transaction نشطة)
                        if (!$db->inTransaction()) {
                            $deductedPackagingIds = [];
                        }
                        
                        if (!empty($materialsConsumption['packaging'])) {
                        // تجميع العناصر المكررة بنفس material_id لتجنب الخصم المكرر
                        $packagingDeductionMap = [];
                        foreach ($materialsConsumption['packaging'] as &$packItem) {
                        $packMaterialId = isset($packItem['material_id']) ? (int)$packItem['material_id'] : 0;
                        $packQuantity = (float)($packItem['quantity'] ?? 0);
                            $packItemName = trim((string)($packItem['name'] ?? ''));
                            $packItemCode = isset($packItem['material_code']) ? (string)$packItem['material_code'] : '';
                            
                        if ($packMaterialId <= 0 && $packQuantity > 0 && $packagingTableExists) {
                                $lookupName = $packItemName;
                            $resolvedRow = $resolvePackagingByName($lookupName);
                            if ($resolvedRow) {
                                $packMaterialId = (int)$resolvedRow['id'];
                                $packItem['material_id'] = $packMaterialId;
                                    error_log('Resolved packaging by name: ' . $lookupName . ' -> ID=' . $packMaterialId);
                            }
                        }
                            
                        if ($packMaterialId > 0 && $packQuantity > 0) {
                                // تجميع الكميات للعناصر بنفس material_id
                                if (!isset($packagingDeductionMap[$packMaterialId])) {
                                    $packagingDeductionMap[$packMaterialId] = [
                                        'material_id' => $packMaterialId,
                                        'quantity' => 0,
                                        'name' => $packItemName,
                                        'code' => $packItemCode,
                                        'unit' => $packItem['unit'] ?? 'وحدة'
                                    ];
                                }
                                $packagingDeductionMap[$packMaterialId]['quantity'] += $packQuantity;
                                error_log('Aggregating packaging item: material_id=' . $packMaterialId . ', quantity=' . $packQuantity . ' (total so far: ' . $packagingDeductionMap[$packMaterialId]['quantity'] . ')');
                            }
                        }
                        unset($packItem);
                        
                        // الآن قم بالخصم للعناصر المجمعة فقط
                        foreach ($packagingDeductionMap as $deductionItem) {
                            $packMaterialId = (int)$deductionItem['material_id'];
                            $packQuantity = (float)$deductionItem['quantity'];
                            $packItemName = trim((string)$deductionItem['name']);
                            $packItemCode = isset($deductionItem['code']) ? (string)$deductionItem['code'] : '';
                            
                            // التحقق من أن هذا العنصر لم يتم خصمه بالفعل في هذه المعاملة
                            if (isset($deductedPackagingIds[$packMaterialId])) {
                                error_log('WARNING: Packaging item ID=' . $packMaterialId . ' already deducted in this transaction! Skipping duplicate deduction.');
                                continue;
                            }
                            
                            error_log('Processing aggregated packaging item: material_id=' . $packMaterialId . ', quantity=' . $packQuantity . ', name=' . $packItemName . ', code=' . $packItemCode);
                            
                            if ($packMaterialId > 0 && $packQuantity > 0) {
                                $materialNameForLog = $packItemName;
                                $materialUnitForLog = $deductionItem['unit'] ?? 'وحدة';

                                // الحصول على الكمية الحالية قبل الخصم مباشرة مع FOR UPDATE لتجنب الخصم المزدوج
                                // FOR UPDATE يضمن أن لا يمكن لأي معاملة أخرى خصم نفس المادة في نفس الوقت
                                $currentQuantityCheck = $db->queryOne(
                                    "SELECT name, unit, quantity FROM packaging_materials WHERE id = ? FOR UPDATE",
                                        [$packMaterialId]
                                    );
                                
                                if (!$currentQuantityCheck) {
                                    error_log('WARNING: Packaging material ID=' . $packMaterialId . ' not found! Skipping deduction.');
                                    continue;
                                }
                                
                                $actualQuantityBefore = (float)($currentQuantityCheck['quantity'] ?? 0);
                                if (!empty($currentQuantityCheck['name'])) {
                                    $materialNameForLog = $currentQuantityCheck['name'];
                                }
                                if (!empty($currentQuantityCheck['unit'])) {
                                    $materialUnitForLog = $currentQuantityCheck['unit'];
                                }
                                
                                // التحقق من أن الكمية المتاحة كافية
                                if ($actualQuantityBefore < $packQuantity) {
                                    error_log('WARNING: Insufficient stock for packaging material ID=' . $packMaterialId . '. Available=' . $actualQuantityBefore . ', Required=' . $packQuantity);
                                    // لا نوقف العملية، فقط نسجل تحذير
                                }
                                
                                // استخدام actualQuantityBefore للتوافق مع الكود السابق
                                $quantityBefore = $actualQuantityBefore;
                                
                                error_log('Deducting from packaging_materials: ID=' . $packMaterialId . ', Quantity=' . $packQuantity . ', Before=' . $actualQuantityBefore);
                                
                                try {
                            $db->execute(
                                "UPDATE packaging_materials 
                                 SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                 WHERE id = ?",
                                [$packQuantity, $packMaterialId]
                            );

                                    // التحقق من أن الخصم تم بنجاح
                                    $verifyDeduction = $db->queryOne(
                                        "SELECT quantity FROM packaging_materials WHERE id = ?",
                                        [$packMaterialId]
                                    );
                                    $quantityAfter = null;
                                    if ($verifyDeduction) {
                                        $quantityAfter = (float)($verifyDeduction['quantity'] ?? 0);
                                        $expectedAfter = max($actualQuantityBefore - $packQuantity, 0);
                                        error_log('Deduction successful: ID=' . $packMaterialId . ', After=' . $quantityAfter . ' (expected: ' . $expectedAfter . ', difference: ' . ($quantityAfter - $expectedAfter) . ')');
                                        
                                        // إذا كان الفرق غير متوقع، سجل تحذير
                                        if (abs($quantityAfter - $expectedAfter) > 0.001) {
                                            error_log('WARNING: Unexpected deduction result! ID=' . $packMaterialId . ', Expected=' . $expectedAfter . ', Actual=' . $quantityAfter . ', Difference=' . ($quantityAfter - $expectedAfter));
                                        } else {
                                            // تسجيل أن هذا العنصر تم خصمه بنجاح فقط عند نجاح الخصم
                                            $deductedPackagingIds[$packMaterialId] = true;
                                        }
                                    }
                                } catch (Exception $deductionError) {
                                    error_log('Packaging deduction ERROR: ' . $deductionError->getMessage() . ' | ID=' . $packMaterialId . ', Qty=' . $packQuantity);
                                }

                                // تسجيل في packaging_usage_logs باستخدام القيم الفعلية
                                if ($packagingUsageLogsExists && $quantityBefore !== null && $quantityAfter !== null) {
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
                    }
                    } catch (Exception $packagingDeductionError) {
                    error_log('Packaging deduction error: ' . $packagingDeductionError->getMessage());
                }
                } // نهاية else - فقط عند عدم قيام batchCreationCreate بالخصم
                
                // خصم تلقائي بناءً على نوع الكرتونة المخزن في القالب
                try {
                    $cartonType = $template['carton_type'] ?? null;
                    if ($cartonType && in_array($cartonType, ['kilo', 'half', 'quarter', 'third'])) {
                        error_log('=== Starting carton-based auto-deduction: carton_type=' . $cartonType . ', quantity=' . $quantity);
                        
                        // تعريف قواعد الخصم لكل نوع كرتونة
                        $cartonRules = [
                            'kilo' => ['target_code' => 'PKG002', 'threshold' => 6, 'divisor' => 6],
                            'half' => ['target_code' => 'PKG001', 'threshold' => 12, 'divisor' => 12],
                            'quarter' => ['target_code' => 'PKG041', 'threshold' => 24, 'divisor' => 24],
                            'third' => ['target_code' => 'PKG041', 'threshold' => 18, 'divisor' => 18],
                        ];
                        
                        $rule = $cartonRules[$cartonType];
                        
                        if ($quantity >= $rule['threshold']) {
                            $targetCodeKey = $rule['target_code'];
                            $targetDisplayCode = $formatPackagingCode($targetCodeKey) ?? ('PKG-' . substr($targetCodeKey, 3));
                            
                            // حساب الكمية المطلوبة للخصم
                            $quantityForBoxes = (int)floor((float)$quantity);
                            $additionalQty = intdiv(max($quantityForBoxes, 0), $rule['divisor']);
                            
                            error_log('Carton auto-deduction: type=' . $cartonType . ', target=' . $targetDisplayCode . ', quantity=' . $quantity . ', additionalQty=' . $additionalQty);
                            
                            if ($additionalQty > 0 && $packagingTableExists) {
                                // البحث عن المادة المستهدفة في قاعدة البيانات
                                $targetMaterialId = null;
                                $targetMaterialName = 'مادة تعبئة ' . $targetDisplayCode;
                                $targetMaterialUnit = 'قطعة';
                                
                                try {
                                    $directSearch = $db->queryOne(
                                        "SELECT id, name, unit, material_id FROM packaging_materials 
                                         WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) = ?
                                            OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(material_id, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE ?
                                            OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(name, ''), '-', ''), ' ', ''), '_', ''), '.', '')) LIKE ?
                                         LIMIT 1",
                                        [$targetCodeKey, $targetCodeKey . '%', '%' . $targetCodeKey . '%']
                                    );
                                    
                                    if ($directSearch && !empty($directSearch['id'])) {
                                        $targetMaterialId = (int)$directSearch['id'];
                                        if (!empty($directSearch['name'])) {
                                            $targetMaterialName = $directSearch['name'];
                                        }
                                        if (!empty($directSearch['unit'])) {
                                            $targetMaterialUnit = $directSearch['unit'];
                                        }
                                        error_log('Target material found: ' . $targetDisplayCode . ', ID=' . $targetMaterialId);
                                    }
                                } catch (Exception $searchError) {
                                    error_log('Error searching for target material ' . $targetDisplayCode . ': ' . $searchError->getMessage());
                                }
                                
                                if ($targetMaterialId !== null) {
                                    // التحقق إذا كانت المادة موجودة بالفعل في القائمة
                                    $targetExists = false;
                                    foreach ($materialsConsumption['packaging'] as &$existingItem) {
                                        $existingCodeKey = isset($existingItem['material_code']) 
                                            ? $normalizePackagingCodeKey($existingItem['material_code']) 
                                            : null;
                                        $existingMaterialId = isset($existingItem['material_id']) ? (int)$existingItem['material_id'] : 0;
                                        
                                        if (($existingCodeKey === $targetCodeKey) || ($existingMaterialId === $targetMaterialId)) {
                                            $existingItem['quantity'] += $additionalQty;
                                            $existingItem['material_code'] = $targetDisplayCode;
                                            $existingItem['material_id'] = $targetMaterialId;
                                            $targetExists = true;
                                            error_log('Target material merged with existing: ' . $targetDisplayCode . ', New Qty=' . $existingItem['quantity']);
                                            break;
                                        }
                                    }
                                    unset($existingItem);
                                    
                                    if (!$targetExists) {
                                        $targetProductId = ensureProductionMaterialProductId($targetMaterialName, 'packaging', $targetMaterialUnit);
                                        
                                        $materialsConsumption['packaging'][] = [
                                            'material_id' => $targetMaterialId,
                                            'quantity' => $additionalQty,
                                            'name' => $targetMaterialName,
                                            'unit' => $targetMaterialUnit,
                                            'product_id' => $targetProductId,
                                            'supplier_id' => null,
                                            'template_item_id' => null,
                                            'material_code' => $targetDisplayCode
                                        ];
                                        error_log('Target material added to consumption: ' . $targetDisplayCode . ', ID=' . $targetMaterialId . ', Qty=' . $additionalQty);
                                    }
                                    
                                    // تنفيذ الخصم من قاعدة البيانات
                                    try {
                                        $currentQuantityCheck = $db->queryOne(
                                            "SELECT name, unit, quantity FROM packaging_materials WHERE id = ? FOR UPDATE",
                                            [$targetMaterialId]
                                        );
                                        
                                        if ($currentQuantityCheck) {
                                            $quantityBefore = (float)($currentQuantityCheck['quantity'] ?? 0);
                                            $materialNameForLog = !empty($currentQuantityCheck['name']) ? $currentQuantityCheck['name'] : $targetMaterialName;
                                            $materialUnitForLog = !empty($currentQuantityCheck['unit']) ? $currentQuantityCheck['unit'] : $targetMaterialUnit;
                                            
                                            error_log('Deducting from packaging_materials: ID=' . $targetMaterialId . ', Quantity=' . $additionalQty . ', Before=' . $quantityBefore);
                                            
                                            $db->execute(
                                                "UPDATE packaging_materials 
                                                 SET quantity = GREATEST(quantity - ?, 0), updated_at = NOW() 
                                                 WHERE id = ?",
                                                [$additionalQty, $targetMaterialId]
                                            );
                                            
                                            // التحقق من نجاح الخصم
                                            $verifyDeduction = $db->queryOne(
                                                "SELECT quantity FROM packaging_materials WHERE id = ?",
                                                [$targetMaterialId]
                                            );
                                            if ($verifyDeduction) {
                                                $quantityAfter = (float)($verifyDeduction['quantity'] ?? 0);
                                                error_log('Carton auto-deduction successful: ID=' . $targetMaterialId . ', After=' . $quantityAfter);
                                                
                                                // تسجيل في packaging_usage_logs إذا كان موجوداً
                                                if ($packagingUsageLogsExists && $quantityBefore !== null) {
                                                    $quantityUsed = $quantityBefore - $quantityAfter;
                                                    
                                                    if ($quantityUsed > 0) {
                                                        try {
                                                            $db->execute(
                                                                "INSERT INTO packaging_usage_logs 
                                                                 (material_id, material_name, material_code, source_table, quantity_before, quantity_used, quantity_after, unit, used_by) 
                                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                                                [
                                                                    $targetMaterialId,
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
                                                            error_log('Carton auto-deduction usage log insert failed: ' . $packagingUsageInsertError->getMessage());
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } catch (Exception $deductionError) {
                                        error_log('Carton auto-deduction ERROR: ' . $deductionError->getMessage() . ' | ID=' . $targetMaterialId . ', Qty=' . $additionalQty);
                                    }
                                } else {
                                    error_log('WARNING: Target material ' . $targetDisplayCode . ' not found in database!');
                                }
                            }
                        } else {
                            error_log('Carton auto-deduction skipped: quantity (' . $quantity . ') < threshold (' . $rule['threshold'] . ')');
                        }
                    } else {
                        error_log('Carton auto-deduction skipped: carton_type not set or invalid (' . ($cartonType ?? 'NULL') . ')');
                    }
                } catch (Exception $cartonDeductionError) {
                    error_log('Carton auto-deduction error: ' . $cartonDeductionError->getMessage());
                }
                
                // تم إزالة منطق الخصم التلقائي القديم - النظام يعتمد الآن فقط على نوع الكرتونة المخزن في القالب
                
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

// جلب موردي الطحينة المتاحين (الموردين الذين لديهم طحينة في tahini_stock)
$tahiniSuppliers = [];
$tahiniStockTableCheck = $db->queryOne("SHOW TABLES LIKE 'tahini_stock'");
if (!empty($tahiniStockTableCheck)) {
    try {
        $tahiniSuppliersData = $db->query(
            "SELECT DISTINCT s.id, s.name, s.type 
             FROM tahini_stock ts
             INNER JOIN suppliers s ON ts.supplier_id = s.id
             WHERE ts.quantity > 0 AND s.status = 'active'
             ORDER BY s.name"
        );
        $tahiniSuppliers = $tahiniSuppliersData;
    } catch (Exception $e) {
        error_log('Failed to load tahini suppliers: ' . $e->getMessage());
        $tahiniSuppliers = [];
    }
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
    'nuts' => 'المكسرات',
    'sesame' => 'السمسم',
    'tahini' => 'الطحينة'
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
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" data-auto-refresh="true">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات -->
<script>
// إعادة تحميل الصفحة تلقائياً بعد أي رسالة (نجاح أو خطأ) لمنع تكرار الطلبات
(function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    // التحقق من وجود رسالة نجاح أو خطأ
    const alertElement = successAlert || errorAlert;
    
    if (alertElement && alertElement.dataset.autoRefresh === 'true') {
        // انتظار 3 ثوانٍ لإعطاء المستخدم وقتاً لرؤية الرسالة
        setTimeout(function() {
            // إعادة تحميل الصفحة بدون معاملات GET لمنع تكرار الطلبات
            const currentUrl = new URL(window.location.href);
            // إزالة معاملات success و error من URL
            currentUrl.searchParams.delete('success');
            currentUrl.searchParams.delete('error');
            // إعادة تحميل الصفحة
            window.location.href = currentUrl.toString();
        }, 3000);
    }
})();
</script>

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
                    <a href="<?php echo getDashboardUrl('manager'); ?>?page=product_templates&section=specifications" class="btn btn-light btn-sm">
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
                        <a class="text-decoration-none" href="<?php echo getDashboardUrl('manager'); ?>?page=product_templates&section=specifications">
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

    <!-- زر طباعة التقرير الشامل -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="mb-1"><i class="bi bi-printer me-2"></i>طباعة التقرير الشامل</h5>
                    <p class="text-muted mb-0">طباعة تقرير شامل بجميع تفاصيل الحركات كفاتورة</p>
                </div>
                <div class="d-flex gap-2">
                    <?php
                    $printUrl = getRelativeUrl('print_production_report.php');
                    $printParams = [
                        'report_day' => $selectedReportDay,
                        'report_type' => $reportFilterType,
                        'supply_category' => $supplyCategoryParam,
                        'report_query' => $reportFilterQuery,
                        'period' => ($selectedReportDay === $productionReportsTodayDate && $selectedReportDay === $productionReportsMonthEnd) ? 'day' : 'month'
                    ];
                    $printUrlWithParams = $printUrl . '?' . http_build_query($printParams);
                    ?>
                    <a href="<?php echo htmlspecialchars($printUrlWithParams); ?>" 
                       target="_blank" 
                       class="btn btn-primary">
                        <i class="bi bi-printer me-2"></i>طباعة التقرير الشامل
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card production-report-card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1"><i class="bi bi-calendar-month me-2"></i>ملخص الشهر الحالي (تراكمي)</h4>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($monthRangeLabelStart); ?>
                        إلى
                        <?php echo htmlspecialchars($monthRangeLabelEnd); ?>
                        <?php if ($productionReportsMonthEnd === $productionReportsTodayDate): ?>
                            <span class="badge bg-info text-white ms-2">حتى اليوم</span>
                        <?php endif; ?>
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
    
    <!-- ملخص اليوم الحالي -->
    <?php if ($productionReportsTodayDate === $productionReportsMonthEnd && $selectedReportDay === $productionReportsTodayDate): ?>
        <?php
        $todayReports = getConsumptionSummary($productionReportsTodayDate, $productionReportsTodayDate);
        $todayPackagingTotals = productionPageAggregateTotals($todayReports['packaging']['items'] ?? []);
        $todayRawTotals = productionPageAggregateTotals($todayReports['raw']['items'] ?? []);
        $todayNet = round($todayPackagingTotals['net'] + $todayRawTotals['net'], 3);
        $todayMovements = productionPageSumMovements($todayReports['packaging']['items'] ?? [])
            + productionPageSumMovements($todayReports['raw']['items'] ?? []);
        ?>
        <div class="card production-report-card shadow-sm mb-4 border-info">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="mb-1"><i class="bi bi-calendar-day me-2 text-info"></i>تفاصيل اليوم الحالي</h4>
                        <p class="text-muted mb-0">
                            <?php echo htmlspecialchars(function_exists('formatDate') ? formatDate($productionReportsTodayDate) : $productionReportsTodayDate); ?>
                            <span class="badge bg-info text-white ms-2">لحظي</span>
                        </p>
                    </div>
                    <span class="badge bg-info text-white">
                        <?php echo date('H:i'); ?>
                    </span>
                </div>
                <div class="production-summary-grid mt-3">
                    <?php if ($showPackagingReports): ?>
                        <div class="summary-card border-info">
                            <span class="summary-label">استهلاك التعبئة اليوم</span>
                            <span class="summary-value text-info">
                                <?php echo number_format((float)($todayPackagingTotals['total_out'] ?? 0), 3); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($showRawReports): ?>
                        <div class="summary-card border-info">
                            <span class="summary-label">استهلاك المواد الخام اليوم</span>
                            <span class="summary-value text-info">
                                <?php echo number_format((float)($todayRawTotals['total_out'] ?? 0), 3); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div class="summary-card border-info">
                        <span class="summary-label">الصافي اليوم</span>
                        <span class="summary-value text-info">
                            <?php echo number_format($todayNet, 3); ?>
                        </span>
                    </div>
                    <div class="summary-card border-info">
                        <span class="summary-label">حركات اليوم</span>
                        <span class="summary-value text-info">
                            <?php echo number_format($todayMovements); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

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
window.tahiniSuppliers = <?php
$tahiniSuppliersForJs = is_array($tahiniSuppliers) ? $tahiniSuppliers : [];
echo json_encode(array_map(function($supplier) {
    return [
        'id' => (int)($supplier['id'] ?? 0),
        'name' => $supplier['name'] ?? '',
        'type' => $supplier['type'] ?? ''
    ];
}, $tahiniSuppliersForJs), JSON_UNESCAPED_UNICODE);
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
    const type = (component.type || '').toString().toLowerCase();
    const key = (component.key || '').toString().toLowerCase();
    const name = ((component.name || component.label || '').toString().toLowerCase());

    const filterByTypes = (allowedTypes) => suppliers.filter(supplier => {
        const supplierType = (supplier.type || '').toString().toLowerCase();
        return allowedTypes.some(allowedType => supplierType === allowedType.toLowerCase());
    });

    // يجب التحقق من الشمع أولاً لتجنب تعارضه مع العسل (مثل "شمع عسل")
    // للشمع
    if (type === 'beeswax' || key.startsWith('beeswax') || name.includes('شمع') || name.includes('beeswax') || key.includes('wax')) {
        return filterByTypes(['beeswax']);
    }

    // للعسل - فقط إذا لم يكن شمع
    if (isHoneyComponent(component)) {
        return filterByTypes(['honey']);
    }

    // لمواد التعبئة
    if (type === 'packaging' || key.startsWith('pack_') || name.includes('تعبئة') || name.includes('packaging')) {
        return filterByTypes(['packaging']);
    }

    // لزيت الزيتون
    if (type === 'olive_oil' || key.startsWith('olive') || name.includes('زيت زيتون') || name.includes('olive oil') || name.includes('olive_oil')) {
        return filterByTypes(['olive_oil']);
    }

    // للمشتقات
    if (type === 'derivatives' || key.startsWith('derivative') || name.includes('مشتق') || name.includes('derivative')) {
        return filterByTypes(['derivatives']);
    }

    // للمكسرات
    if (type === 'nuts' || key.startsWith('nuts') || name.includes('مكسرات') || name.includes('nuts')) {
        return filterByTypes(['nuts']);
    }

    // للطحينة - استخدام موردي السمسم الذين لديهم طحينة متاحة في tahini_stock
    if (type === 'tahini' || key.startsWith('tahini') || name.includes('طحينة') || name.includes('tahini')) {
        // استخدام موردي الطحينة المتاحين بدلاً من جميع موردي السمسم
        const tahiniSuppliers = window.tahiniSuppliers || [];
        if (tahiniSuppliers.length > 0) {
            return tahiniSuppliers;
        }
        // إذا لم تكن هناك بيانات، استخدم موردي السمسم كبديل
        return filterByTypes(['sesame']);
    }

    // إذا لم يتم العثور على نوع محدد، إرجاع قائمة فارغة بدلاً من جميع الموردين
    // لتجنب عرض موردين غير مناسبين
    return [];
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

            // التحقق من تطابق نوع العسل المحدد مسبقاً في القالب (مطابقة مرنة)
            // نوع العسل محدد تلقائياً من بيانات القالب ولا يجب أن يقوم المستخدم بتحديده
            if (!matchedOption && defaultValue !== '') {
                const varietyLower = normalizedVariety.toLocaleLowerCase('ar');
                // المطابقة الدقيقة
                if (varietyLower === defaultValueLower) {
                    matchedOption = option;
                }
                // المطابقة المرنة: التحقق إذا كان نوع العسل يحتوي على القيمة الافتراضية أو العكس
                else if (!matchedOption && (varietyLower.includes(defaultValueLower) || defaultValueLower.includes(varietyLower))) {
                    matchedOption = option;
                }
                // المطابقة الجزئية: البحث عن تطابق في الكلمات
                else if (!matchedOption && defaultValueLower.length > 2) {
                    const defaultValueWords = defaultValueLower.split(/\s+/).filter(w => w.length > 2);
                    const varietyWords = varietyLower.split(/\s+/).filter(w => w.length > 2);
                    if (defaultValueWords.length > 0 && varietyWords.length > 0) {
                        const hasMatch = defaultValueWords.some(dw => varietyWords.some(vw => vw.includes(dw) || dw.includes(vw)));
                        if (hasMatch) {
                            matchedOption = option;
                        }
                    }
                }
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

    // اختيار نوع العسل تلقائياً إذا كان محدداً في القالب
    // نوع العسل محدد تلقائياً من بيانات القالب ولا يجب أن يقوم المستخدم بتحديده
    if (matchedOption) {
        matchedOption.selected = true;
        selectEl.value = matchedOption.value;
        placeholderOption.selected = false;
        // إذا كان نوع العسل محدداً من القالب، قم بتعطيل الحقل لمنع المستخدم من تغييره
        if (defaultValue !== '') {
            selectEl.disabled = true;
            selectEl.style.backgroundColor = '#e9ecef';
            selectEl.style.cursor = 'not-allowed';
            selectEl.title = 'نوع العسل محدد تلقائياً من بيانات القالب';
        }
        // إذا تم تحديد نوع العسل تلقائياً من القالب، قم بتشغيل حدث change
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    } else if (defaultValue !== '' && entries.length > 0) {
        // إذا كان هناك نوع عسل محدد في القالب لكن لم يتم العثور على تطابق دقيق،
        // جرب اختيار أول خيار متاح كبديل (إذا كان هناك خيار واحد فقط)
        if (entries.length === 1) {
            const firstOption = selectEl.querySelector('option:not([value=""])');
            if (firstOption) {
                firstOption.selected = true;
                selectEl.value = firstOption.value;
                placeholderOption.selected = false;
                selectEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    } else if (entries.length === 0) {
        // إذا لم يكن نوع العسل متوفراً في المخزون لكنه موجود في القالب، أضفه مع تحذير
        if (defaultValue !== '') {
            // إضافة نوع العسل من القالب حتى لو لم يكن متوفراً في المخزون
            const fallbackOption = document.createElement('option');
            fallbackOption.value = defaultValue;
            fallbackOption.dataset.raw = 0;
            fallbackOption.dataset.filtered = 0;
            fallbackOption.textContent = `${defaultValue} — (القيمة المعرّفة في القالب)`;
            fallbackOption.selected = true;
            selectEl.appendChild(fallbackOption);
            selectEl.value = defaultValue;
            placeholderOption.selected = false;
            // تعطيل الحقل لأنه محدد من القالب
            selectEl.disabled = true;
            selectEl.style.backgroundColor = '#e9ecef';
            selectEl.style.cursor = 'not-allowed';
            selectEl.title = 'نوع العسل محدد تلقائياً من بيانات القالب';
            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
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

    // إصلاح نموذج الفلترة في قسم التقارير
    const reportsForm = document.querySelector('#productionReportsSection form[method="get"]');
    if (reportsForm) {
        // التأكد من أن section=reports موجود دائماً
        let sectionInput = reportsForm.querySelector('input[name="section"]');
        if (!sectionInput) {
            sectionInput = document.createElement('input');
            sectionInput.type = 'hidden';
            sectionInput.name = 'section';
            sectionInput.value = 'reports';
            reportsForm.appendChild(sectionInput);
        }
        
        // التأكد من أن page=production موجود دائماً
        let pageInput = reportsForm.querySelector('input[name="page"]');
        if (!pageInput) {
            pageInput = document.createElement('input');
            pageInput.type = 'hidden';
            pageInput.name = 'page';
            pageInput.value = 'production';
            reportsForm.appendChild(pageInput);
        }
        
        reportsForm.addEventListener('submit', function(e) {
            // التأكد من أن جميع الحقول موجودة وقيمها صحيحة
            const reportDay = reportsForm.querySelector('input[name="report_day"]');
            const reportType = reportsForm.querySelector('select[name="report_type"]');
            const supplyCategory = reportsForm.querySelector('select[name="supply_category"]');
            const reportQuery = reportsForm.querySelector('input[name="report_query"]');
            
            // التأكد من أن section=reports موجود دائماً
            if (sectionInput) {
                sectionInput.value = 'reports';
            }
            
            // التأكد من أن page=production موجود دائماً
            if (pageInput) {
                pageInput.value = 'production';
            }
            
            // التحقق من صحة التاريخ
            if (reportDay && reportDay.value) {
                const selectedDate = new Date(reportDay.value);
                const minDate = new Date(reportDay.min);
                const maxDate = new Date(reportDay.max);
                
                if (selectedDate < minDate || selectedDate > maxDate) {
                    e.preventDefault();
                    alert('يرجى اختيار تاريخ صحيح ضمن النطاق المسموح');
                    reportDay.focus();
                    return false;
                }
            }
            
            // التأكد من أن النموذج يرسل البيانات بشكل صحيح
            // لا نمنع الإرسال الافتراضي، فقط نتأكد من أن البيانات صحيحة
        });
        
        // إضافة event listener لأزرار الفلترة للتأكد من عملها
        const filterButtons = reportsForm.querySelectorAll('button[type="submit"]');
        filterButtons.forEach(function(button) {
            button.addEventListener('click', function(e) {
                // التأكد من أن النموذج جاهز للإرسال
                if (!reportsForm.checkValidity()) {
                    e.preventDefault();
                    reportsForm.reportValidity();
                    return false;
                }
            });
        });
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
        const key = (component.key || '').toString().toLowerCase();
        const name = ((component.name || component.label || '').toString().toLowerCase());
        
        // يجب التحقق من الشمع أولاً لتجنب تعارضه مع العسل (مثل "شمع عسل")
        // هذا يجب أن يكون قبل أي فحص آخر
        if (key.startsWith('beeswax') || name.includes('شمع') || key.includes('beeswax') || key.includes('wax')) {
            return 'beeswax';
        }
        
        const type = (component.type || '').toString().toLowerCase();
        if (type) {
            // إذا كان النوع محدداً وكان شمع، لا تغيره
            if (type === 'beeswax') {
                return 'beeswax';
            }
            if (requiresHoneyVariety(component) && !HONEY_COMPONENT_TYPES.includes(type)) {
                return 'honey_general';
            }
            return type;
        }
        if (requiresHoneyVariety(component)) {
            return 'honey_general';
        }
        
        if (key.startsWith('pack_')) return 'packaging';
        if (key.startsWith('raw_')) return 'raw_general';
        if (key.startsWith('olive')) return 'olive_oil';
        if (key.startsWith('derivative')) return 'derivatives';
        if (key.startsWith('nuts')) return 'nuts';
        
        // فحص العسل من الاسم أيضاً - فقط إذا لم يكن شمع
        if (key.startsWith('honey_') || name.includes('عسل')) {
            if (name.includes('مصفى') || name.includes('filtered') || key.includes('filtered')) {
                return 'honey_filtered';
            }
            if (name.includes('خام') || name.includes('raw') || key.includes('raw')) {
                return 'honey_raw';
            }
            return 'honey_general';
        }
        
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
        tahini: 'السمسم',
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
            // يجب استثناء مكونات الشمع من مجموعة العسل لتجنب التضارب
            if (canonicalType === 'beeswax') {
                return false;
            }
            const componentName = ((component.name || component.label || '').toString().toLowerCase());
            const componentKeyLower = ((component.key || component.name || '').toString().toLowerCase());
            // استثناء مكونات الشمع من مجموعة العسل
            const isBeeswax = componentName.includes('شمع') || componentKeyLower.includes('beeswax') || componentKeyLower.includes('wax');
            if (isBeeswax) {
                return false;
            }
            const honeyVarietyRequired = requiresHoneyVariety(component);
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

    // تجميع مكونات الشمع في بطاقة منفصلة
    const beeswaxComponentEntries = components
        .filter(component => {
            const canonicalType = determineComponentType(component);
            const componentName = ((component.name || component.label || '').toString().toLowerCase());
            const componentKeyLower = ((component.key || component.name || '').toString().toLowerCase());
            const isBeeswaxByNameOrKey = componentName.includes('شمع') || componentKeyLower.includes('beeswax') || componentKeyLower.includes('wax');
            
            return (
                canonicalType === 'beeswax'
                || isBeeswaxByNameOrKey
            );
        })
        .map(component => ({
            component,
            key: resolveComponentKey(component)
        }));

    const beeswaxGroup = {
        entries: beeswaxComponentEntries,
        baseEntry: beeswaxComponentEntries[0] || null,
        extraEntries: beeswaxComponentEntries.slice(1),
        renderAggregated: beeswaxComponentEntries.length > 0,
        processedKeys: new Set()
    };

    components.forEach(function(component) {
        let canonicalType = determineComponentType(component);
        const componentKey = resolveComponentKey(component);
        const honeyVarietyRequired = requiresHoneyVariety(component);
        // لا تغير النوع إلى honey_general إذا كان المكون شمع
        if (honeyVarietyRequired && canonicalType !== 'beeswax' && !HONEY_COMPONENT_TYPES.includes(canonicalType)) {
            canonicalType = 'honey_general';
        }
        // فحص شامل لتحديد ما إذا كان المكوّن عسل أو شمع
        const componentName = ((component.name || component.label || '').toString().toLowerCase());
        const componentKeyLower = (componentKey || '').toString().toLowerCase();
        
        // فحص شامل لتحديد ما إذا كان المكوّن شمع - يجب التحقق من الشمع أولاً لتجنب تعارضه مع العسل
        const isBeeswaxByNameOrKey = componentName.includes('شمع') || componentKeyLower.includes('beeswax') || componentKeyLower.includes('wax');
        const isBeeswaxType = canonicalType === 'beeswax' || isBeeswaxByNameOrKey;
        
        // فحص شامل لتحديد ما إذا كان المكوّن عسل - لكن فقط إذا لم يكن شمع
        // إذا كان المكون يحتوي على "شمع"، فلا يجب أن يعامل كعسل حتى لو كان يحتوي على "عسل"
        const isHoneyByNameOrKey = !isBeeswaxType && (componentName.includes('عسل') || componentKeyLower.includes('honey'));
        
        const isHoneyType = !isBeeswaxType && (
            isHoneyComponent(component)
            || honeyVarietyRequired
            || canonicalType === 'honey_raw'
            || canonicalType === 'honey_filtered'
            || canonicalType === 'honey_main'
            || canonicalType === 'honey_general'
            || isHoneyByNameOrKey
        );

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

        // تجميع جميع مكوّنات الشمع في بطاقة واحدة فقط
        // تأكد من عرض بطاقة الشمع بشكل منفصل حتى لو كان هناك عسل
        if (isBeeswaxType) {
            // إذا كان هناك مكوّنات شمع وتم بالفعل عرض بطاقة الشمع
            // تأكد من عرض بطاقة الشمع فقط للمكوّن الأول (baseEntry)
            if (beeswaxGroup.baseEntry && componentKey !== beeswaxGroup.baseEntry.key) {
                return; // تخطي المكوّنات الإضافية للشمع
            }
            // إذا كان هذا هو المكوّن الأول من الشمع، تأكد من أنه لم يتم عرضه من قبل
            if (beeswaxGroup.baseEntry && componentKey === beeswaxGroup.baseEntry.key) {
                if (beeswaxGroup.processedKeys.has(componentKey)) {
                    return; // تم عرضه بالفعل
                }
                beeswaxGroup.processedKeys.add(componentKey);
            }
            // إذا لم يكن هناك baseEntry لكن هناك مكونات شمع، يجب عرض بطاقة الشمع
            // هذا يحدث عادة عندما يكون هناك شمع فقط أو شمع مع عسل
            if (!beeswaxGroup.baseEntry && beeswaxComponentEntries.length > 0) {
                // في هذه الحالة، يجب أن يكون هناك baseEntry، لكن للاحتياط
                // سيتم عرض بطاقة الشمع لهذا المكون
            }
        }

        const isAggregatedHoneyCard = isHoneyType
            && honeyGroup.baseEntry
            && componentKey === honeyGroup.baseEntry.key
            && honeyGroup.renderAggregated;

        // تأكد من عرض بطاقة الشمع إذا كان المكوّن شمع وكان هو baseEntry
        // يجب أن يتم عرض بطاقة الشمع بشكل منفصل حتى لو كان هناك عسل
        const isAggregatedBeeswaxCard = isBeeswaxType
            && beeswaxGroup.baseEntry
            && componentKey === beeswaxGroup.baseEntry.key
            && beeswaxGroup.renderAggregated;
        
        // إذا كان المكوّن شمع ولم يكن هناك baseEntry محدد (يجب أن لا يحدث)
        // لكن للاحتياط، تأكد من عرض بطاقة الشمع إذا كان المكوّن شمع
        if (isBeeswaxType && !beeswaxGroup.baseEntry && beeswaxComponentEntries.length > 0) {
            // في هذه الحالة، يجب أن يكون هناك baseEntry، لكن للاحتياط
            // سيتم معالجة هذا المكوّن كبطاقة شمع عادية
        }

        const aggregatedEntries = isAggregatedHoneyCard ? honeyGroup.entries : [];
        const extraHoneyEntries = isAggregatedHoneyCard ? honeyGroup.extraEntries : [];
        const aggregatedBeeswaxEntries = isAggregatedBeeswaxCard ? beeswaxGroup.entries : [];
        const extraBeeswaxEntries = isAggregatedBeeswaxCard ? beeswaxGroup.extraEntries : [];

        const effectiveType = isAggregatedHoneyCard ? 'honey_main' : (isAggregatedBeeswaxCard ? 'beeswax' : canonicalType);
        const safeTypeClass = effectiveType.replace(/[^a-z0-9_-]/g, '') || 'generic';

        const col = document.createElement('div');
        col.className = 'col-12 col-lg-6';

        const card = document.createElement('div');
        card.className = `component-card component-type-${safeTypeClass}`;
        card.style.setProperty('--component-accent', accentColors[effectiveType] || accentColors.default);

        if (!isHoneyType && !isBeeswaxType) {
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
            // إضافة عنوان للمكوّنات المجمعة - العسل
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
            // إضافة عنوان للمكوّنات المجمعة - الشمع
            // تأكد من عرض بطاقة الشمع بشكل منفصل حتى لو كان هناك عسل
            // يجب أن يتم عرض بطاقة الشمع إذا كان المكون شمع وكان هو baseEntry أو إذا كان الشمع فقط
            const shouldShowBeeswaxCard = isBeeswaxType && (
                (beeswaxGroup.baseEntry && componentKey === beeswaxGroup.baseEntry.key) ||
                (!beeswaxGroup.baseEntry && beeswaxComponentEntries.length > 0 && beeswaxComponentEntries.some(e => e.key === componentKey))
            );
            
            if (shouldShowBeeswaxCard) {
                const beeswaxEntriesToShow = isAggregatedBeeswaxCard ? aggregatedBeeswaxEntries : 
                    (beeswaxGroup.entries.length > 0 ? beeswaxGroup.entries : 
                    (beeswaxComponentEntries.length > 0 ? beeswaxComponentEntries.map(e => e.component) : [{ name: component.name || component.label || 'شمع عسل', quantity: component.quantity || component.amount || '' }]));
                
                if (beeswaxEntriesToShow.length > 0) {
                    const header = document.createElement('div');
                    header.className = 'component-card-header mb-2';
                    const title = document.createElement('span');
                    title.className = 'component-card-title';
                    const firstEntry = beeswaxEntriesToShow[0];
                    const entryName = firstEntry.name || firstEntry.component?.name || firstEntry.label || firstEntry.component?.label || 'مكوّن شمع';
                    if (beeswaxEntriesToShow.length === 1) {
                        title.textContent = entryName;
                    } else {
                        title.textContent = 'مواد الشمع (' + beeswaxEntriesToShow.length + ' مكوّن)';
                    }
                    header.appendChild(title);
                    card.appendChild(header);
                    
                    // إضافة معلومات المكوّنات
                    const meta = document.createElement('div');
                    meta.className = 'component-card-meta mb-2';
                    const metaList = beeswaxEntriesToShow.map(entry => {
                        const name = entry.name || entry.component?.name || entry.label || entry.component?.label || 'مكوّن';
                        const qty = entry.quantity || entry.amount || entry.component?.quantity || entry.component?.amount || '';
                        return name + (qty ? ' (' + qty + ')' : '');
                    }).join('، ');
                    meta.innerHTML = '<i class="bi bi-hexagon me-2"></i><span>' + metaList + '</span>';
                    card.appendChild(meta);
                }
            }
        }

        const controlLabel = document.createElement('label');
        controlLabel.className = 'form-label fw-semibold small text-muted mb-1';
        if (isHoneyType) {
            // لمكوّنات العسل، استخدم دائماً "مورد العسل"
            controlLabel.textContent = aggregatedEntries.length > 1 
                ? 'مورد العسل (سيتم تطبيقه على جميع مكوّنات العسل)'
                : 'مورد العسل';
        } else if (isBeeswaxType) {
            // لمكوّنات الشمع، استخدم دائماً "مورد الشمع"
            // تأكد من عرض بطاقة الشمع بشكل منفصل حتى لو كان هناك عسل
            const beeswaxEntries = isAggregatedBeeswaxCard ? aggregatedBeeswaxEntries : (beeswaxGroup.baseEntry && componentKey === beeswaxGroup.baseEntry.key ? beeswaxGroup.entries : []);
            const beeswaxCount = beeswaxEntries.length > 0 ? beeswaxEntries.length : 1;
            controlLabel.textContent = beeswaxCount > 1 
                ? 'مورد الشمع (سيتم تطبيقه على جميع مكوّنات الشمع)'
                : 'مورد الشمع';
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

        // الحصول على الموردين المناسبين للمكون حسب نوعه
        let suppliersForComponent = getSuppliersForComponent(component);
        let suppliersList = [];
        
        if (isHoneyType) {
            // لمكوّنات العسل، استخدم موردين العسل فقط حتى لو كان عددهم 0
            suppliersList = suppliersForComponent;
            // تأكد من أن الموردين المفلترين هم فقط موردين العسل
            const allSuppliers = window.productionSuppliers || [];
            suppliersList = allSuppliers.filter(supplier => supplier.type === 'honey');
        } else if (isBeeswaxType) {
            // لمكوّنات الشمع، استخدم موردين الشمع فقط حتى لو كان عددهم 0
            // تأكد من عرض بطاقة الشمع بشكل منفصل حتى لو كان هناك عسل
            suppliersList = suppliersForComponent;
            // تأكد من أن الموردين المفلترين هم فقط موردين الشمع
            const allSuppliers = window.productionSuppliers || [];
            suppliersList = allSuppliers.filter(supplier => supplier.type === 'beeswax');
        } else {
            // للمكوّنات الأخرى، استخدم الموردين المفلترين حسب نوع المكون فقط
            // إذا لم يتم العثور على موردين محددين، لا تستخدم fallback لجميع الموردين
            suppliersList = suppliersForComponent.length ? suppliersForComponent : [];
            
            // إذا كان نوع المكون معروفاً لكن لا يوجد موردين، حاول البحث بشكل أكثر تحديداً
            if (suppliersList.length === 0 && canonicalType) {
                const allSuppliers = window.productionSuppliers || [];
                // البحث عن موردين بنوع يطابق نوع المكون
                const typeMatch = allSuppliers.filter(supplier => {
                    const supplierType = (supplier.type || '').toString().toLowerCase();
                    const componentType = canonicalType.toString().toLowerCase();
                    return supplierType === componentType;
                });
                if (typeMatch.length > 0) {
                    suppliersList = typeMatch;
                }
            }
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

        // الاختيار التلقائي للمورد إذا كان هناك مورد واحد فقط
        // ينطبق على جميع أنواع المكونات (المواد الخام، المكونات، مواد التعبئة)
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
            // تحديد نوع العسل من القالب المحدد مسبقاً - يجب أن يكون محدد تلقائياً من بيانات القالب
            let defaultHoneyVariety = component.honey_variety || component.variety || '';
            
            // إذا لم يكن نوع العسل محدداً مباشرة في component، استخرجه من اسم المادة كحل بديل
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
            
            // إذا كان نوع العسل محدداً من القالب، اجعل الحقل غير قابل للتعديل (readonly)
            if (defaultHoneyVariety) {
                honeySelect.disabled = true;
                honeySelect.style.backgroundColor = '#e9ecef';
                honeySelect.style.cursor = 'not-allowed';
            } else {
                honeySelect.disabled = true; // معطل حتى يتم اختيار المورد
            }

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
                
                syncHiddenInputs();
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
            
            // إذا كان نوع العسل محدد في القالب، تأكد من اختياره تلقائياً
            if (isHoneyType && honeySelect && defaultHoneyVariety) {
                // انتظر قليلاً لضمان تحديث الخيارات أولاً
                setTimeout(() => {
                    const honeyValue = honeySelect.dataset.defaultValue || defaultHoneyVariety;
                    if (honeyValue && honeySelect.querySelector(`option[value="${honeyValue}"]`)) {
                        honeySelect.value = honeyValue;
                        honeySelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }, 100);
            }
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
        // إذا كان الحقل معطلاً، تحقق من أن له قيمة (محدد من القالب)
        if (field.disabled) {
            // إذا كان الحقل معطلاً ولديه قيمة، فهذا يعني أنه محدد تلقائياً من القالب - هذا جيد
            if (field.value && field.value.trim()) {
                continue; // الحقل معطل لكن له قيمة من القالب - هذا صحيح
            }
            // إذا كان الحقل معطلاً وليس له قيمة، تحقق من أن المورد محدد
            const componentCard = field.closest('.component-card');
            if (componentCard) {
                const supplierSelect = componentCard.querySelector('select[name^="material_suppliers"]');
                if (!supplierSelect || !supplierSelect.value || supplierSelect.value.trim() === '') {
                    e.preventDefault();
                    alert('يرجى اختيار مورد العسل أولاً');
                    if (supplierSelect) {
                        supplierSelect.focus();
                    }
                    return false;
                }
                // إذا كان المورد محدد لكن نوع العسل غير محدد، هذا خطأ
                e.preventDefault();
                alert('يرجى تحديد نوع العسل لدى المورد المختار');
                field.focus();
                return false;
            }
        }
        // إذا كان الحقل غير معطل، تحقق من أن له قيمة
        if (!field.disabled && (!field.value || !field.value.trim())) {
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

        const { autoOpen = true, showError = true } = options;
        
        if (showError && message) {
            const fallbackMessages = document.getElementById('barcodeFallbackMessages');
            if (fallbackMessages) {
                fallbackMessages.classList.remove('d-none');
                fallbackMessages.innerHTML = '<div class="alert alert-warning alert-dismissible fade show" role="alert">' +
                    '<i class="bi bi-exclamation-triangle me-2"></i>' +
                    '<strong>تنبيه:</strong> ' + message +
                    '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
                    '</div>';
            } else {
                console.warn(message);
            }
        }
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

// التحقق من رسالة الخطأ الخاصة بالطلب المكرر وتحديث الصفحة تلقائياً
function checkForDuplicateRequestError() {
    const errorAlerts = document.querySelectorAll('.alert-danger');
    errorAlerts.forEach(function(alert) {
        const alertText = alert.textContent || alert.innerText;
        if (alertText.includes('تم معالجة هذا الطلب من قبل')) {
            setTimeout(function() {
                window.location.replace(window.location.href);
            }, 2000);
        }
    });
}

// التحقق فوراً إذا كان DOM جاهزاً
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkForDuplicateRequestError);
} else {
    checkForDuplicateRequestError();
}

// مراقبة التغييرات في DOM للتحقق من الرسائل المضافة ديناميكياً
const observer = new MutationObserver(function(mutations) {
    checkForDuplicateRequestError();
});

observer.observe(document.body, {
    childList: true,
    subtree: true
});
</script>