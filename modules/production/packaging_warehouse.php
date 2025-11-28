<?php
/**
 * صفحة مخزن أدوات التعبئة
 * Packaging Warehouse Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/production_helper.php';

if (!function_exists('generateNextPackagingMaterialCode')) {
    /**
     * توليد كود أداة التعبئة التالي بصيغة PKG-###
     */
    function generateNextPackagingMaterialCode($db, bool $usePackagingTable): string
    {
        $prefix = 'PKG-';
        $prefixLength = strlen($prefix) + 1; // بداية الجزء الرقمي في الدالة SUBSTRING
        $maxNumber = 0;
        $maxDigits = 3;

        if ($usePackagingTable) {
            $row = $db->queryOne(
                "SELECT material_id 
                 FROM packaging_materials 
                 WHERE material_id REGEXP '^{$prefix}[0-9]+$' 
                 ORDER BY CAST(SUBSTRING(material_id, {$prefixLength}) AS UNSIGNED) DESC 
                 LIMIT 1"
            );
            if (!empty($row['material_id'])) {
                $numericPart = substr($row['material_id'], strlen($prefix));
                if ($numericPart !== '') {
                    $maxNumber = max($maxNumber, (int)$numericPart);
                    $maxDigits = max($maxDigits, strlen($numericPart));
                }
            }
        }

        static $productsHasMaterialIdColumn = null;
        if ($productsHasMaterialIdColumn === null) {
            $productsTableExists = $db->queryOne("SHOW TABLES LIKE 'products'");
            if (empty($productsTableExists)) {
                $productsHasMaterialIdColumn = false;
            } else {
                $columnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'material_id'");
                $productsHasMaterialIdColumn = !empty($columnCheck);
            }
        }

        if ($productsHasMaterialIdColumn) {
            $row = $db->queryOne(
                "SELECT material_id 
                 FROM products 
                 WHERE material_id IS NOT NULL 
                   AND material_id REGEXP '^{$prefix}[0-9]+$' 
                 ORDER BY CAST(SUBSTRING(material_id, {$prefixLength}) AS UNSIGNED) DESC 
                 LIMIT 1"
            );
            if (!empty($row['material_id'])) {
                $numericPart = substr($row['material_id'], strlen($prefix));
                if ($numericPart !== '') {
                    $maxNumber = max($maxNumber, (int)$numericPart);
                    $maxDigits = max($maxDigits, strlen($numericPart));
                }
            }
        }

        $nextNumber = $maxNumber + 1;
        if ($nextNumber <= 0) {
            $nextNumber = 1;
        }

        $padLength = max(3, $maxDigits);
        $numericSegment = str_pad((string)$nextNumber, $padLength, '0', STR_PAD_LEFT);

        return $prefix . $numericSegment;
    }
}

if (!function_exists('packagingMaterialCodeExists')) {
    /**
     * التحقق من وجود كود أداة التعبئة مسبقاً.
     */
    function packagingMaterialCodeExists($db, string $code, bool $usePackagingTable): bool
    {
        $code = trim($code);
        if ($code === '') {
            return true;
        }

        if ($usePackagingTable) {
            $row = $db->queryOne(
                "SELECT id FROM packaging_materials WHERE material_id = ? LIMIT 1",
                [$code]
            );
            if (!empty($row)) {
                return true;
            }
        } else {
            static $packagingTableExists = null;
            if ($packagingTableExists === null) {
                $packagingTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
            }
            if ($packagingTableExists) {
                $row = $db->queryOne(
                    "SELECT id FROM packaging_materials WHERE material_id = ? LIMIT 1",
                    [$code]
                );
                if (!empty($row)) {
                    return true;
                }
            }
        }

        static $productsHasMaterialIdColumn = null;
        if ($productsHasMaterialIdColumn === null) {
            $productsTableExists = $db->queryOne("SHOW TABLES LIKE 'products'");
            if (empty($productsTableExists)) {
                $productsHasMaterialIdColumn = false;
            } else {
                $columnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'material_id'");
                $productsHasMaterialIdColumn = !empty($columnCheck);
            }
        }

        if ($productsHasMaterialIdColumn) {
            $row = $db->queryOne(
                "SELECT id FROM products WHERE material_id = ? LIMIT 1",
                [$code]
            );
            if (!empty($row)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('getPackagingTypeOptions')) {
    /**
     * استرجاع قائمة أنواع أدوات التعبئة من جدول الأنواع إن وجد، وإلا استخدام القيم الحالية.
     *
     * @return array<int, string>
     */
    function getPackagingTypeOptions($db, bool $usePackagingTable): array
    {
        $options = [];
        $candidates = [
            'packaging_tool_types',
            'packaging_material_types',
            'tool_types',
        ];

        foreach ($candidates as $tableName) {
            $tableExists = $db->queryOne("SHOW TABLES LIKE '{$tableName}'");
            if (empty($tableExists)) {
                continue;
            }

            $columns = $db->query("SHOW COLUMNS FROM {$tableName}");
            if (empty($columns) || !is_array($columns)) {
                continue;
            }

            $columnNames = array_map(static function ($column): string {
                return $column['Field'] ?? '';
            }, $columns);

            $labelColumn = null;
            foreach (['name', 'type_name', 'label', 'title'] as $candidateColumn) {
                if (in_array($candidateColumn, $columnNames, true)) {
                    $labelColumn = $candidateColumn;
                    break;
                }
            }

            if ($labelColumn === null) {
                continue;
            }

            $orderColumn = in_array('sort_order', $columnNames, true) ? 'sort_order' : $labelColumn;
            $rows = $db->query("SELECT {$labelColumn} AS label FROM {$tableName} ORDER BY {$orderColumn}");

            if (!empty($rows) && is_array($rows)) {
                foreach ($rows as $row) {
                    $label = isset($row['label']) ? trim((string)$row['label']) : '';
                    if ($label !== '') {
                        $options[] = $label;
                    }
                }
            }

            if (!empty($options)) {
                $options = array_values(array_unique($options));
                break;
            }
        }

        if (!empty($options)) {
            return $options;
        }

        if ($usePackagingTable) {
            $rows = $db->query(
                "SELECT DISTINCT type 
                 FROM packaging_materials 
                 WHERE type IS NOT NULL AND TRIM(type) <> '' 
                 ORDER BY type"
            );
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $label = isset($row['type']) ? trim((string)$row['type']) : '';
                    if ($label !== '') {
                        $options[] = $label;
                    }
                }
            }
        } else {
            $typeColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'type'");
            if (!empty($typeColumnCheck)) {
                $rows = $db->query(
                    "SELECT DISTINCT type 
                     FROM products 
                     WHERE type IS NOT NULL AND TRIM(type) <> '' 
                       AND (category LIKE '%تغليف%' OR category LIKE '%packaging%' OR type LIKE '%تغليف%')
                     ORDER BY type"
                );
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $label = isset($row['type']) ? trim((string)$row['type']) : '';
                        if ($label !== '') {
                            $options[] = $label;
                        }
                    }
                }
            }
        }

        $options[] = 'ملصقات';
        $normalized = [];
        foreach ($options as $label) {
            $label = trim((string)$label);
            if ($label === '') {
                continue;
            }
            $normalized[$label] = $label;
        }

        return array_values($normalized);
    }
}

if (!function_exists('buildPackagingReportHtmlDocument')) {
    /**
     * إنشاء مستند HTML لتقرير مخزن أدوات التعبئة.
     *
     * @param array<string, mixed> $report
     * @param bool $lowStockOnly إذا كان true، يعرض فقط الأدوات ذات المخزون القليل أو المعدوم
     */
    function buildPackagingReportHtmlDocument(array $report, bool $lowStockOnly = false): string
    {
        $generatedAt = htmlspecialchars((string)($report['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $generatedBy = htmlspecialchars((string)($report['generated_by'] ?? ''), ENT_QUOTES, 'UTF-8');
        $typesCount = isset($report['types_count']) ? (int)$report['types_count'] : 0;
        $totalMaterials = isset($report['total_materials']) ? (float)$report['total_materials'] : 0.0;
        $totalQuantity = isset($report['total_quantity']) ? (float)$report['total_quantity'] : 0.0;
        $zeroQuantity = isset($report['zero_quantity']) ? (int)$report['zero_quantity'] : 0;
        $totalProductions = isset($report['total_productions']) ? (int)$report['total_productions'] : 0;
        $totalUsed = isset($report['total_used']) ? (float)$report['total_used'] : 0.0;
        $lastUpdated = htmlspecialchars((string)($report['last_updated'] ?? 'غير متاح'), ENT_QUOTES, 'UTF-8');

        $typeBreakdown = $report['type_breakdown'] ?? [];
        if (!is_array($typeBreakdown)) {
            $typeBreakdown = [];
        }

        $topItems = $report['top_items'] ?? [];
        if (!is_array($topItems)) {
            $topItems = [];
        }

        // عند الطباعة، نعرض فقط الأدوات ذات المخزون القليل أو المعدوم
        if ($lowStockOnly) {
            // تصفية top_items لعرض فقط الأدوات التي quantity <= 0 أو قليل (أقل من 10)
            $topItems = array_filter($topItems, static function ($item) {
                $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
                return $quantity <= 0 || $quantity < 10;
            });
            
            // إعادة ترتيب top_items حسب الكمية (الأقل أولاً للطباعة)
            usort($topItems, static function ($a, $b) {
                $qtyA = isset($a['quantity']) ? (float)$a['quantity'] : 0.0;
                $qtyB = isset($b['quantity']) ? (float)$b['quantity'] : 0.0;
                return $qtyA <=> $qtyB;
            });
            
            // عند الطباعة، نعرض جميع الأدوات ذات المخزون القليل (وليس فقط 8)
            
            // إعادة حساب type_breakdown بناءً على الأدوات المفلترة فقط
            $filteredTypeBreakdown = [];
            foreach ($topItems as $item) {
                $typeLabel = isset($item['type']) ? (string)$item['type'] : 'غير مصنف';
                $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
                $unit = isset($item['unit']) ? (string)$item['unit'] : 'وحدة';
                
                if (!isset($filteredTypeBreakdown[$typeLabel])) {
                    $filteredTypeBreakdown[$typeLabel] = [
                        'count' => 0,
                        'total_quantity' => 0.0,
                        'units' => []
                    ];
                }
                
                $filteredTypeBreakdown[$typeLabel]['count']++;
                $filteredTypeBreakdown[$typeLabel]['total_quantity'] += $quantity;
                if (!isset($filteredTypeBreakdown[$typeLabel]['units'][$unit])) {
                    $filteredTypeBreakdown[$typeLabel]['units'][$unit] = 0.0;
                }
                $filteredTypeBreakdown[$typeLabel]['units'][$unit] += $quantity;
            }
            
            // حساب المتوسط لكل فئة
            foreach ($filteredTypeBreakdown as $typeLabel => &$breakdownEntry) {
                $count = max(1, (int)$breakdownEntry['count']);
                $breakdownEntry['average_quantity'] = $breakdownEntry['total_quantity'] / $count;
            }
            unset($breakdownEntry);
            
            $typeBreakdown = $filteredTypeBreakdown;
        } else {
            // في العرض العادي، نعرض فقط أعلى 8 أدوات من حيث الكمية
            $topItems = array_slice($topItems, 0, 8);
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>تقرير مخزن أدوات التعبئة</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: "Cairo", "Tajawal", "Segoe UI", Tahoma, sans-serif;
            background: #f8fafc;
            margin: 0;
            padding: 32px;
            color: #0f172a;
        }
        .report-wrapper {
            max-width: 960px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
            padding: 32px;
        }
        header {
            display: flex;
            flex-direction: column;
            gap: 16px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 28px;
        }
        header h1 {
            margin: 0;
            font-size: 28px;
            color: #1d4ed8;
        }
        header .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            color: #475569;
            font-size: 14px;
        }
        header .meta span {
            background: #e2e8f0;
            padding: 6px 14px;
            border-radius: 999px;
        }
        .summary {
            margin-bottom: 32px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .summary-card {
            background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
            color: #ffffff;
            border-radius: 18px;
            padding: 18px 20px;
            box-shadow: 0 18px 38px rgba(37, 99, 235, 0.18);
        }
        .summary-card .label {
            font-size: 13px;
            opacity: 0.85;
            margin-bottom: 8px;
            display: block;
        }
        .summary-card .value {
            font-size: 22px;
            font-weight: 700;
        }
        .table-section {
            margin-bottom: 32px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 24px;
        }
        .table-section h2 {
            margin: 0 0 16px;
            font-size: 20px;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        thead {
            background: #e2e8f0;
        }
        th, td {
            padding: 14px 16px;
            text-align: right;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        th {
            font-weight: 600;
            color: #1f2937;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 600;
        }
        .empty-state {
            text-align: center;
            font-size: 15px;
            color: #64748b;
            padding: 22px 0;
        }
        .notes {
            background: #ecfeff;
            border: 1px solid #bae6fd;
            border-radius: 16px;
            padding: 20px 24px;
            color: #0c4a6e;
        }
        .notes h3 {
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 18px;
            color: #0369a1;
        }
        .notes ul {
            margin: 0;
            padding-right: 20px;
            line-height: 1.7;
        }
        @media (max-width: 768px) {
            body {
                padding: 18px;
            }
            .report-wrapper {
                padding: 24px;
            }
            header h1 {
                font-size: 24px;
            }
            table {
                font-size: 13px;
            }
            th, td {
                padding: 10px 12px;
            }
        }
    </style>
</head>
<body>
<div class="report-wrapper">
    <header>
        <h1>تقرير مخزن أدوات التعبئة</h1>
        <div class="meta">
            <span>تاريخ التوليد: <?php echo $generatedAt; ?></span>
            <span>أُعد بواسطة: <?php echo $generatedBy; ?></span>
            <span>آخر تحديث للسجلات: <?php echo $lastUpdated; ?></span>
            <span>فئات الأدوات: <?php echo number_format($typesCount); ?></span>
        </div>
    </header>

    <section class="summary">
        <div class="summary-card">
            <span class="label">إجمالي الأدوات</span>
            <span class="value"><?php echo number_format($totalMaterials); ?></span>
        </div>
        <div class="summary-card">
            <span class="label">إجمالي المخزون الحالي</span>
            <span class="value"><?php echo number_format($totalQuantity, 2); ?></span>
        </div>
        <div class="summary-card">
            <span class="label">أدوات بدون مخزون</span>
            <span class="value"><?php echo number_format($zeroQuantity); ?></span>
        </div>
        <div class="summary-card">
            <span class="label">عمليات الإنتاج </span>
            <span class="value"><?php echo number_format($totalProductions); ?></span>
        </div>
    </section>


    <section class="table-section">
        <h2><?php echo $lowStockOnly ? 'الأدوات ذات المخزون القليل أو المعدوم' : 'أعلى الأدوات من حيث الكمية'; ?></h2>
        <?php if (empty($topItems)): ?>
            <div class="empty-state"><?php echo $lowStockOnly ? 'لا توجد أدوات بمخزون قليل أو معدوم حالياً.' : 'لا توجد بيانات لعرض أفضل الأدوات حالياً.'; ?></div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>اسم الأداة</th>
                    <th>الكود/المعرّف</th>
                    <th>الفئة</th>
                    <th>الكمية المتاحة</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($topItems as $index => $item): ?>
                    <?php
                    $name = htmlspecialchars((string)($item['name'] ?? '-'), ENT_QUOTES, 'UTF-8');
                    $alias = htmlspecialchars((string)($item['alias'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $code = htmlspecialchars((string)($item['code'] ?? '-'), ENT_QUOTES, 'UTF-8');
                    $type = htmlspecialchars((string)($item['type'] ?? '-'), ENT_QUOTES, 'UTF-8');
                    $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0.0;
                    $unit = htmlspecialchars((string)($item['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr>
                        <td><?php echo (int)$index + 1; ?></td>
                        <td>
                            <div><?php echo $name; ?></div>
                            <?php if ($alias !== ''): ?>
                                <div style="font-size:12px; color:#0ea5e9; margin-top:4px;"><?php echo $alias; ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $code !== '' ? $code : '-'; ?></td>
                        <td><?php echo $type; ?></td>
                        <td>
                            <span class="badge">
                                <?php echo number_format($quantity, 2) . ' ' . $unit; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
        <?php
        return (string)ob_get_clean();
    }
}

if (!function_exists('storePackagingReportDocument')) {
    /**
     * حفظ تقرير مخزن أدوات التعبئة في مجلد خاص وإرجاع مسارات العرض.
     *
     * @param array<string, mixed> $report
     * @return array<string, string>|null
     */
    function storePackagingReportDocument(array $report): ?array
    {
        try {
            if (!function_exists('ensurePrivateDirectory')) {
                return null;
            }

            $basePath = defined('REPORTS_PRIVATE_PATH')
                ? REPORTS_PRIVATE_PATH
                : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__, 2) . '/reports'));

            $basePath = rtrim((string)$basePath, '/\\');
            if ($basePath === '') {
                return null;
            }

            ensurePrivateDirectory($basePath);

            $exportsDir = $basePath . DIRECTORY_SEPARATOR . 'exports';
            $reportDir = $exportsDir . DIRECTORY_SEPARATOR . 'packaging';

            ensurePrivateDirectory($exportsDir);
            ensurePrivateDirectory($reportDir);

            if (!is_dir($reportDir) || !is_writable($reportDir)) {
                error_log('Packaging report directory not writable: ' . $reportDir);
                return null;
            }

            // إنشاء تقريرين: واحد للعرض العادي وواحد للطباعة (مخزون قليل فقط)
            $document = buildPackagingReportHtmlDocument($report, false);
            $printDocument = buildPackagingReportHtmlDocument($report, true);

            // حذف الملفات القديمة (العادية وملفات الطباعة)
            $patterns = [
                $reportDir . DIRECTORY_SEPARATOR . 'packaging-report-*.html',
                $reportDir . DIRECTORY_SEPARATOR . 'packaging-report-print-*.html'
            ];
            foreach ($patterns as $pattern) {
                foreach (glob($pattern) ?: [] as $file) {
                    if (is_string($file)) {
                        @unlink($file);
                    }
                }
            }

            $token = bin2hex(random_bytes(8));
            $filename = sprintf('packaging-report-%s-%s.html', date('Ymd-His'), $token);
            $printFilename = sprintf('packaging-report-print-%s-%s.html', date('Ymd-His'), $token);
            
            $fullPath = $reportDir . DIRECTORY_SEPARATOR . $filename;
            $printFullPath = $reportDir . DIRECTORY_SEPARATOR . $printFilename;

            if (@file_put_contents($fullPath, $document) === false) {
                return null;
            }
            
            if (@file_put_contents($printFullPath, $printDocument) === false) {
                error_log('Failed to create print version of packaging report');
            }

            $relativePath = 'exports/packaging/' . $filename;
            $printRelativePath = 'exports/packaging/' . $printFilename;
            $viewerPath = '/reports/view.php?type=export&file=' . rawurlencode($relativePath) . '&token=' . $token;
            $printPath = '/reports/view.php?type=export&file=' . rawurlencode($printRelativePath) . '&token=' . $token . '&print=1';

            $absoluteViewer = function_exists('getAbsoluteUrl')
                ? getAbsoluteUrl(ltrim($viewerPath, '/'))
                : $viewerPath;
            $absolutePrint = function_exists('getAbsoluteUrl')
                ? getAbsoluteUrl(ltrim($printPath, '/'))
                : $printPath;

            return [
                'relative_path' => $relativePath,
                'viewer_path' => $viewerPath,
                'print_path' => $printPath,
                'absolute_viewer_url' => $absoluteViewer,
                'absolute_print_url' => $absolutePrint,
                'token' => $token,
            ];
        } catch (Throwable $error) {
            error_log('Packaging report storage failed: ' . $error->getMessage());
            return null;
        }
    }
}

if (!function_exists('normalizePackagingString')) {
    /**
     * Normalize strings for case-insensitive, whitespace-insensitive comparison.
     */
    function normalizePackagingString(?string $value): string
    {
        $normalized = mb_strtolower(trim((string)$value), 'UTF-8');
        // Replace multiple whitespace characters with a single space.
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        return $normalized ?? '';
    }
}

if (!function_exists('shouldShowPackagingUseButton')) {
    /**
     * Determine whether the quick-use button should be displayed for the given material.
     *
     * @param array<string, mixed> $material
     */
    function shouldShowPackagingUseButton(array $material): bool
    {
        $type = normalizePackagingString($material['type'] ?? $material['category'] ?? '');
        $name = normalizePackagingString($material['name'] ?? '');
        $alias = normalizePackagingString($material['alias'] ?? '');

        $targetTypes = [
            'اكياس - لوازم التعبئة',
            'اكياس - لوازم التعبئه',
            'أكياس - لوازم التعبئة',
            'أكياس - لوازم التعبئه',
        ];

        $normalizedTargetTypes = array_map('normalizePackagingString', $targetTypes);
        if ($type !== '') {
            foreach ($normalizedTargetTypes as $target) {
                if ($type === $target) {
                    return true;
                }
            }
            if (mb_strpos($type, 'اكياس', 0, 'UTF-8') !== false && mb_strpos($type, 'لوازم', 0, 'UTF-8') !== false) {
                return true;
            }
        }

        $targetNames = ['بابلز', 'bubble', 'bubbles', 'شريط لاصق', 'لاصق', 'adhesive tape', 'tape'];
        foreach ($targetNames as $targetName) {
            $normalizedTarget = normalizePackagingString($targetName);
            if ($normalizedTarget !== '') {
                if ($name !== '' && mb_strpos($name, $normalizedTarget, 0, 'UTF-8') !== false) {
                    return true;
                }
                if ($alias !== '' && mb_strpos($alias, $normalizedTarget, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}

requireAnyRole(['production', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// قراءة الرسائل من session (Post-Redirect-Get pattern)
applyPRGPattern($error, $success);

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// الفلترة والبحث
$filters = [
    'search' => $_GET['search'] ?? '',
    'material_id' => isset($_GET['material_id']) ? intval($_GET['material_id']) : 0,
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? ''
];

// التحقق من وجود جدول packaging_materials
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
$usePackagingTable = !empty($tableCheck);

if ($usePackagingTable) {
    $aliasColumnCheck = $db->queryOne("SHOW COLUMNS FROM packaging_materials LIKE 'alias'");
    if (empty($aliasColumnCheck)) {
        try {
            $db->execute("ALTER TABLE `packaging_materials` ADD COLUMN `alias` VARCHAR(255) DEFAULT NULL AFTER `name`");
        } catch (Throwable $aliasError) {
            error_log('Packaging alias column error: ' . $aliasError->getMessage());
        }
    }
}

// إنشاء جدول تسجيل التلفيات إذا لم يكن موجوداً
$damageLogTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_damage_logs'");
if (empty($damageLogTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `packaging_damage_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `material_id` int(11) NOT NULL,
              `material_name` varchar(255) DEFAULT NULL,
              `source_table` enum('packaging_materials','products') NOT NULL DEFAULT 'packaging_materials',
              `quantity_before` decimal(15,4) DEFAULT 0.0000,
              `damaged_quantity` decimal(15,4) NOT NULL,
              `quantity_after` decimal(15,4) DEFAULT 0.0000,
              `unit` varchar(50) DEFAULT NULL,
              `reason` text DEFAULT NULL,
              `recorded_by` int(11) NOT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `material_id` (`material_id`),
              KEY `recorded_by` (`recorded_by`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating packaging_damage_logs table: " . $e->getMessage());
    }
}

$usageLogTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_usage_logs'");
$hasPackagingUsageLogs = !empty($usageLogTableCheck);
if (!$hasPackagingUsageLogs) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `packaging_usage_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `material_id` int(11) NOT NULL,
              `material_name` varchar(255) DEFAULT NULL,
              `material_code` varchar(100) DEFAULT NULL,
              `source_table` enum('packaging_materials','products') NOT NULL DEFAULT 'packaging_materials',
              `quantity_before` decimal(15,4) DEFAULT 0.0000,
              `quantity_used` decimal(15,4) NOT NULL,
              `quantity_after` decimal(15,4) DEFAULT 0.0000,
              `unit` varchar(50) DEFAULT NULL,
              `used_by` int(11) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `material_id` (`material_id`),
              KEY `used_by` (`used_by`),
              KEY `source_table` (`source_table`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $hasPackagingUsageLogs = true;
    } catch (Exception $e) {
        $hasPackagingUsageLogs = false;
        error_log("Error creating packaging_usage_logs table: " . $e->getMessage());
    }
}

// معالجة طلبات إضافة الكميات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_packaging_alias') {
        header('Content-Type: application/json; charset=utf-8');

        if (!$usePackagingTable) {
            echo json_encode([
                'success' => false,
                'message' => 'ميزة الاسم المستعار غير متاحة في الوضع الحالي.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $materialId = intval($_POST['material_id'] ?? 0);
        $aliasValue = trim((string)($_POST['alias'] ?? ''));

        if ($materialId <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'معرّف الأداة غير صحيح.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $original = $db->queryOne(
                "SELECT id, alias FROM packaging_materials WHERE id = ?",
                [$materialId]
            );

            if (!$original) {
                throw new Exception('أداة التعبئة غير موجودة.');
            }

            $db->execute(
                "UPDATE packaging_materials SET alias = ?, updated_at = NOW() WHERE id = ?",
                [$aliasValue !== '' ? $aliasValue : null, $materialId]
            );

            logAudit(
                $currentUser['id'],
                'update_packaging_alias',
                'packaging_materials',
                $materialId,
                ['alias' => $original['alias'] ?? null],
                ['alias' => $aliasValue !== '' ? $aliasValue : null]
            );

            echo json_encode([
                'success' => true,
                'alias' => $aliasValue,
                'message' => 'تم حفظ الاسم المستعار بنجاح.'
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $aliasUpdateError) {
            error_log('Packaging alias update error: ' . $aliasUpdateError->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'تعذّر حفظ الاسم المستعار: ' . $aliasUpdateError->getMessage()
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    } elseif ($action === 'create_packaging_material') {
        if (($currentUser['role'] ?? '') !== 'manager') {
            $error = 'غير مصرح لك بإضافة أدوات التعبئة.';
        } else {
            if (!$usePackagingTable) {
                try {
                    $db->execute("
                        CREATE TABLE IF NOT EXISTS `packaging_materials` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `material_id` varchar(50) NOT NULL COMMENT 'معرف فريد مثل PKG-001',
                          `name` varchar(255) NOT NULL COMMENT 'اسم الأداة',
                          `type` varchar(100) DEFAULT NULL COMMENT 'نوع الأداة',
                          `specifications` varchar(255) DEFAULT NULL COMMENT 'المواصفات',
                          `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
                          `unit` varchar(50) DEFAULT NULL,
                          `unit_price` decimal(15,2) DEFAULT 0.00,
                          `status` enum('active','inactive') DEFAULT 'active',
                          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `material_id` (`material_id`),
                          KEY `type` (`type`),
                          KEY `status` (`status`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                    $usePackagingTable = true;
                } catch (Exception $initError) {
                    $error = 'تعذّر تهيئة جدول أدوات التعبئة: ' . $initError->getMessage();
                }
            }

            if (empty($error)) {
                try {
                    $aliasColumnCheck = $db->queryOne("SHOW COLUMNS FROM packaging_materials LIKE 'alias'");
                    if (empty($aliasColumnCheck)) {
                        $db->execute("ALTER TABLE `packaging_materials` ADD COLUMN `alias` VARCHAR(255) DEFAULT NULL AFTER `name`");
                    }
                } catch (Throwable $aliasInitError) {
                    $error = 'تعذّر تجهيز جدول أدوات التعبئة: ' . $aliasInitError->getMessage();
                }
            }

            $materialCodeInput = trim((string)($_POST['material_id'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $typeValue = trim((string)($_POST['type'] ?? ''));
            $aliasValue = trim((string)($_POST['alias'] ?? ''));
            $specificationsValue = '';
            $statusValue = (string)($_POST['status'] ?? 'active');
            $initialQuantityRaw = $_POST['initial_quantity'] ?? 0;

            $materialCode = $materialCodeInput !== '' ? strtoupper($materialCodeInput) : '';
            if ($materialCode === '') {
                $materialCode = generateNextPackagingMaterialCode($db, $usePackagingTable);
            }

            $allowedStatuses = ['active', 'inactive'];
            if (!in_array($statusValue, $allowedStatuses, true)) {
                $statusValue = 'active';
            }

            $initialQuantity = is_numeric($initialQuantityRaw) ? floatval($initialQuantityRaw) : 0.0;
            if (!is_finite($initialQuantity)) {
                $initialQuantity = 0.0;
            }
            $initialQuantity = max(0.0, round($initialQuantity, 4));

            $unitPrice = 0.0;
            $unitValue = 'قطعة';

            if (empty($error)) {
                if (preg_match('/\s/u', $materialCode)) {
                    $error = 'كود الأداة يجب ألا يحتوي على مسافات.';
                } elseif (mb_strlen($materialCode, 'UTF-8') > 50) {
                    $error = 'كود الأداة يتجاوز الحد الأقصى للطول (50 حرفاً).';
                } elseif ($name === '') {
                    $error = 'يرجى إدخال اسم الأداة.';
                }
            }

            if (empty($error)) {
                try {
                    $db->beginTransaction();

                    $insertAttempts = 0;
                    $maxInsertAttempts = 5;
                    $inserted = false;
                    while ($insertAttempts < $maxInsertAttempts && !$inserted) {
                        if (packagingMaterialCodeExists($db, $materialCode, $usePackagingTable)) {
                            $materialCode = generateNextPackagingMaterialCode($db, $usePackagingTable);
                            $insertAttempts++;
                            continue;
                        }

                        try {
                            $insertResult = $db->execute(
                                "INSERT INTO packaging_materials 
                                 (material_id, name, alias, type, specifications, quantity, unit, unit_price, status, created_at) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                                [
                                    $materialCode,
                                    $name,
                                    $aliasValue !== '' ? $aliasValue : null,
                                    $typeValue !== '' ? $typeValue : null,
                                    $specificationsValue !== '' ? $specificationsValue : null,
                                    $initialQuantity,
                                    $unitValue,
                                    $unitPrice,
                                    $statusValue
                                ]
                            );
                            $inserted = true;
                        } catch (Exception $insertError) {
                            $message = $insertError->getMessage();
                            if (stripos($message, 'duplicate') !== false || stripos($message, 'Duplicate entry') !== false) {
                                $materialCode = generateNextPackagingMaterialCode($db, $usePackagingTable);
                                $insertAttempts++;
                                continue;
                            }
                            throw $insertError;
                        }
                    }

                    if (!$inserted) {
                        throw new Exception('تعذر توليد كود فريد للأداة بعد عدة محاولات.');
                    }

                    $newId = isset($insertResult['insert_id']) && $insertResult['insert_id'] > 0
                        ? (int)$insertResult['insert_id']
                        : (int)$db->getLastInsertId();

                    logAudit(
                        $currentUser['id'],
                        'create_packaging_material',
                        'packaging_materials',
                        $newId,
                        null,
                        [
                            'material_id' => $materialCode,
                            'name' => $name,
                            'initial_quantity' => $initialQuantity,
                            'unit' => $unitValue,
                            'status' => $statusValue
                        ]
                    );

                    $db->commit();

                    if ($initialQuantity > 0) {
                        $initialDetails = 'توريد ابتدائي عند إنشاء أداة التعبئة';
                        if ($aliasValue !== '') {
                            $initialDetails .= ' (اسم مختصر: ' . $aliasValue . ')';
                        }
                        $supplyLogged = recordProductionSupplyLog([
                            'material_category' => 'packaging',
                            'material_label' => $aliasValue !== '' ? $aliasValue : $name,
                            'stock_source' => $usePackagingTable ? 'packaging_materials' : 'products',
                            'stock_id' => $newId,
                            'quantity' => $initialQuantity,
                            'unit' => $unitValue,
                            'details' => $initialDetails,
                            'recorded_by' => $currentUser['id'] ?? null,
                        ]);
                        if (!$supplyLogged) {
                            error_log('packaging_warehouse: failed recording initial supply log for packaging material ' . $newId);
                        }
                    }

                    $successMessage = sprintf('تم إضافة أداة التعبئة "%s" بنجاح.', $name);
                    $redirectParams = ['page' => 'packaging_warehouse'];
                    foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                        if (!empty($_GET[$param])) {
                            $redirectParams[$param] = $_GET[$param];
                        }
                    }

                    preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'حدث خطأ أثناء إضافة الأداة: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'use_packaging_material') {
        header('Content-Type: application/json; charset=utf-8');

        $materialId = intval($_POST['material_id'] ?? 0);
        $useQuantity = 1.0;

        if ($materialId <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'معرّف الأداة غير صحيح.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $db->beginTransaction();

            if ($usePackagingTable) {
                $material = $db->queryOne(
                    "SELECT id, material_id, name, quantity, unit 
                     FROM packaging_materials 
                     WHERE id = ? AND status = 'active' 
                     FOR UPDATE",
                    [$materialId]
                );
            } else {
                $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
                $selectColumns = $unitColumnCheck ? 'id, name, quantity, unit' : 'id, name, quantity';
                $material = $db->queryOne(
                    "SELECT {$selectColumns} 
                     FROM products 
                     WHERE id = ? AND status = 'active' 
                     FOR UPDATE",
                    [$materialId]
                );
                if ($unitColumnCheck && $material && !array_key_exists('unit', $material)) {
                    $material['unit'] = null;
                }
            }

            if (!$material) {
                throw new Exception('أداة التعبئة غير موجودة أو غير مفعّلة.');
            }

            $quantityBefore = (float)($material['quantity'] ?? 0);
            if ($quantityBefore < $useQuantity) {
                throw new Exception('الكمية المتاحة أقل من المطلوب للاستخدام.');
            }

            $quantityAfter = max(round($quantityBefore - $useQuantity, 4), 0.0);

            if ($usePackagingTable) {
                $db->execute(
                    "UPDATE packaging_materials 
                     SET quantity = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$quantityAfter, $materialId]
                );
            } else {
                $db->execute(
                    "UPDATE products 
                     SET quantity = ? 
                     WHERE id = ?",
                    [$quantityAfter, $materialId]
                );
            }

            if ($hasPackagingUsageLogs) {
                try {
                    $db->execute(
                        "INSERT INTO packaging_usage_logs 
                         (material_id, material_name, material_code, source_table, quantity_before, quantity_used, quantity_after, unit, used_by) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [
                            $materialId,
                            $material['name'] ?? null,
                            $material['material_id'] ?? null,
                            $usePackagingTable ? 'packaging_materials' : 'products',
                            $quantityBefore,
                            $useQuantity,
                            $quantityAfter,
                            $material['unit'] ?? 'وحدة',
                            $currentUser['id'] ?? null
                        ]
                    );
                } catch (Exception $logError) {
                    error_log('Packaging use log insert failed: ' . $logError->getMessage());
                }
            }

            logAudit(
                $currentUser['id'],
                'use_packaging_material',
                $usePackagingTable ? 'packaging_materials' : 'products',
                $materialId,
                [
                    'quantity_before' => $quantityBefore
                ],
                [
                    'quantity_after' => $quantityAfter,
                    'used_quantity' => $useQuantity
                ]
            );

            $db->commit();

            $unitLabel = $material['unit'] ?? 'وحدة';
            $materialName = $material['name'] ?? ('أداة #' . $materialId);
            $successMessage = sprintf(
                'تم استخدام %s %s من %s بنجاح.',
                rtrim(rtrim(number_format($useQuantity, 2), '0'), '.'),
                $unitLabel,
                $materialName
            );

            $_SESSION['success_message'] = $successMessage;

            echo json_encode([
                'success' => true,
                'message' => $successMessage,
                'reload' => true
            ], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('Packaging use error: ' . $e->getMessage());
            $errorMessage = $e->getMessage() ?: 'حدث خطأ غير متوقع.';

            echo json_encode([
                'success' => false,
                'message' => 'تعذّر استخدام الأداة: ' . $errorMessage
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    } elseif ($action === 'add_packaging_quantity') {
        $materialId = intval($_POST['material_id'] ?? 0);
        $additionalQuantity = isset($_POST['additional_quantity']) ? round(floatval($_POST['additional_quantity']), 4) : 0.0;
        $notes = trim($_POST['notes'] ?? '');

        if ($materialId <= 0) {
            $error = 'معرف أداة التعبئة غير صحيح.';
        } elseif ($additionalQuantity <= 0) {
            $error = 'يرجى إدخال كمية صحيحة أكبر من الصفر.';
        } else {
            try {
                $db->beginTransaction();

                if ($usePackagingTable) {
                    $material = $db->queryOne(
                        "SELECT id, name, quantity, unit FROM packaging_materials WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                } else {
                    $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
                    $selectColumns = $unitColumnCheck ? 'id, name, quantity, unit' : 'id, name, quantity';
                    $material = $db->queryOne(
                        "SELECT {$selectColumns} FROM products WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                    if ($unitColumnCheck && $material && !array_key_exists('unit', $material)) {
                        $material['unit'] = null;
                    }
                }

                if (!$material) {
                    throw new Exception('أداة التعبئة غير موجودة أو غير مفعّلة.');
                }

                $quantityBefore = floatval($material['quantity'] ?? 0);
                $quantityAfter = $quantityBefore + $additionalQuantity;
                $unitLabel = $material['unit'] ?? 'وحدة';

                if ($usePackagingTable) {
                    $db->execute(
                        "UPDATE packaging_materials 
                         SET quantity = ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                } else {
                    $db->execute(
                        "UPDATE products 
                         SET quantity = ? 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                }

                $auditDetailsAfter = [
                    'quantity_after' => $quantityAfter,
                    'added_quantity' => $additionalQuantity
                ];

                if ($notes !== '') {
                    $auditDetailsAfter['notes'] = mb_substr($notes, 0, 500, 'UTF-8');
                }

                logAudit(
                    $currentUser['id'],
                    'add_packaging_quantity',
                    $usePackagingTable ? 'packaging_materials' : 'products',
                    $materialId,
                    ['quantity_before' => $quantityBefore],
                    $auditDetailsAfter
                );

                $db->commit();

                $supplyDetails = $notes !== '' ? mb_substr($notes, 0, 300, 'UTF-8') : 'إضافة كمية عبر مخزن أدوات التعبئة';
                $supplyLogged = recordProductionSupplyLog([
                    'material_category' => 'packaging',
                    'material_label' => $material['name'] ?? ('أداة #' . $materialId),
                    'stock_source' => $usePackagingTable ? 'packaging_materials' : 'products',
                    'stock_id' => $materialId,
                    'quantity' => $additionalQuantity,
                    'unit' => $unitLabel,
                    'details' => $supplyDetails,
                    'recorded_by' => $currentUser['id'] ?? null,
                ]);
                if (!$supplyLogged) {
                    error_log('packaging_warehouse: failed recording supply log for material ' . $materialId);
                }

                $successMessage = sprintf(
                    'تمت إضافة %.2f %s إلى %s بنجاح.',
                    $additionalQuantity,
                    $unitLabel,
                    $material['name'] ?? ('أداة #' . $materialId)
                );

                $redirectParams = ['page' => 'packaging_warehouse'];
                foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                    if (!empty($_GET[$param])) {
                        $redirectParams[$param] = $_GET[$param];
                    }
                }

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'حدث خطأ أثناء تحديث الكمية: ' . $e->getMessage();
            }

            if (empty($error)) {
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            }
        }
    } elseif ($action === 'record_packaging_damage') {
        $materialId = intval($_POST['material_id'] ?? 0);
        $damagedQuantity = isset($_POST['damaged_quantity']) ? round(floatval($_POST['damaged_quantity']), 4) : 0.0;
        $reason = trim($_POST['reason'] ?? '');

        if ($materialId <= 0) {
            $error = 'معرّف الأداة غير صحيح.';
        } elseif ($damagedQuantity <= 0) {
            $error = 'يرجى إدخال كمية تالفة صحيحة أكبر من الصفر.';
        } elseif ($reason === '') {
            $error = 'يرجى ذكر سبب التلف.';
        } else {
            try {
                $db->beginTransaction();

                if ($usePackagingTable) {
                    $material = $db->queryOne(
                        "SELECT id, name, quantity, unit FROM packaging_materials WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                } else {
                    $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
                    $selectColumns = $unitColumnCheck ? 'id, name, quantity, unit' : 'id, name, quantity';
                    $material = $db->queryOne(
                        "SELECT {$selectColumns} FROM products WHERE id = ? AND status = 'active' FOR UPDATE",
                        [$materialId]
                    );
                    if ($unitColumnCheck && $material && !array_key_exists('unit', $material)) {
                        $material['unit'] = null;
                    }
                }

                if (!$material) {
                    throw new Exception('أداة التعبئة غير موجودة أو غير مفعّلة.');
                }

                $quantityBefore = floatval($material['quantity'] ?? 0);
                if ($damagedQuantity > $quantityBefore) {
                    throw new Exception('الكمية التالفة تتجاوز الكمية المتاحة.');
                }

                $quantityAfter = max($quantityBefore - $damagedQuantity, 0);

                if ($usePackagingTable) {
                    $db->execute(
                        "UPDATE packaging_materials 
                         SET quantity = ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                } else {
                    $db->execute(
                        "UPDATE products 
                         SET quantity = ? 
                         WHERE id = ?",
                        [$quantityAfter, $materialId]
                    );
                }

                $db->execute(
                    "INSERT INTO packaging_damage_logs 
                     (material_id, material_name, source_table, quantity_before, damaged_quantity, quantity_after, unit, reason, recorded_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $materialId,
                        $material['name'] ?? null,
                        $usePackagingTable ? 'packaging_materials' : 'products',
                        $quantityBefore,
                        $damagedQuantity,
                        $quantityAfter,
                        $material['unit'] ?? null,
                        mb_substr($reason, 0, 500, 'UTF-8'),
                        $currentUser['id']
                    ]
                );
                
                // إضافة إلى مخزن توالف المصنع (factory_waste_packaging)
                try {
                    $packagingDamageLogId = $db->getLastInsertId();
                    $tableExists = $db->queryOne("SHOW TABLES LIKE 'factory_waste_packaging'");
                    if ($tableExists) {
                        $userName = $currentUser['full_name'] ?? $currentUser['username'] ?? 'مستخدم';
                        
                        // التحقق من عدم وجود سجل مسبق
                        $existingWaste = $db->queryOne(
                            "SELECT id FROM factory_waste_packaging WHERE packaging_damage_log_id = ?",
                            [$packagingDamageLogId]
                        );
                        
                        if (!$existingWaste) {
                            $db->execute(
                                "INSERT INTO factory_waste_packaging 
                                (packaging_damage_log_id, tool_type, damaged_quantity, unit, added_date, 
                                 recorded_by_user_id, recorded_by_user_name)
                                VALUES (?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $packagingDamageLogId,
                                    $material['name'] ?? 'أداة غير معروفة',
                                    $damagedQuantity,
                                    $material['unit'] ?? null,
                                    date('Y-m-d'),
                                    $currentUser['id'],
                                    $userName
                                ]
                            );
                        }
                    }
                } catch (Throwable $wasteError) {
                    // لا نوقف العملية إذا فشل حفظ في مخزن التوالف، فقط نسجل الخطأ
                    error_log('Warning: Failed to save to factory_waste_packaging: ' . $wasteError->getMessage());
                }

                logAudit(
                    $currentUser['id'],
                    'record_packaging_damage',
                    $usePackagingTable ? 'packaging_materials' : 'products',
                    $materialId,
                    [
                        'quantity_before' => $quantityBefore,
                        'damaged_quantity' => $damagedQuantity
                    ],
                    [
                        'quantity_after' => $quantityAfter,
                        'reason' => mb_substr($reason, 0, 500, 'UTF-8')
                    ]
                );

                $db->commit();

                $unitLabel = $material['unit'] ?? 'وحدة';
                $successMessage = sprintf(
                    'تم تسجيل %.2f %s تالف من %s.',
                    $damagedQuantity,
                    $unitLabel,
                    $material['name'] ?? ('أداة #' . $materialId)
                );

                $redirectParams = ['page' => 'packaging_warehouse'];
                foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                    if (!empty($_GET[$param])) {
                        $redirectParams[$param] = $_GET[$param];
                    }
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $error = 'حدث خطأ أثناء تسجيل التلف: ' . $e->getMessage();
            }

            if (empty($error)) {
                preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
            }
        }
    } elseif ($action === 'update_packaging_material') {
        if (($currentUser['role'] ?? '') !== 'manager') {
            $error = 'غير مصرح لك بتعديل أدوات التعبئة.';
        } else {
            $materialId = intval($_POST['material_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $typeValue = trim($_POST['type'] ?? '');
            $unitValue = trim($_POST['unit'] ?? '');
            $materialCode = trim($_POST['material_code'] ?? '');
            $specificationsValue = trim($_POST['specifications'] ?? '');
            $statusValue = $_POST['status'] ?? 'active';

            $allowedStatuses = ['active', 'inactive'];
            if (!in_array($statusValue, $allowedStatuses, true)) {
                $statusValue = 'active';
            }

            if ($materialId <= 0) {
                $error = 'معرف الأداة غير صالح.';
            } elseif ($name === '') {
                $error = 'يرجى إدخال اسم الأداة.';
            } else {
                try {
                    $db->beginTransaction();

                    if ($usePackagingTable) {
                        $original = $db->queryOne(
                            "SELECT * FROM packaging_materials WHERE id = ? FOR UPDATE",
                            [$materialId]
                        );

                        if (!$original) {
                            throw new Exception('لم يتم العثور على أداة التعبئة المطلوبة.');
                        }

                        $db->execute(
                            "UPDATE packaging_materials
                             SET name = ?, type = ?, unit = ?, material_id = ?, specifications = ?, status = ?, updated_at = NOW()
                             WHERE id = ?",
                            [
                                $name,
                                $typeValue !== '' ? $typeValue : null,
                                $unitValue !== '' ? $unitValue : null,
                                $materialCode !== '' ? $materialCode : null,
                                $specificationsValue !== '' ? $specificationsValue : null,
                                $statusValue,
                                $materialId
                            ]
                        );
                    } else {
                        $original = $db->queryOne(
                            "SELECT * FROM products WHERE id = ? FOR UPDATE",
                            [$materialId]
                        );

                        if (!$original) {
                            throw new Exception('المنتج غير موجود أو غير متاح.');
                        }

                        $updateParts = ["name = ?"];
                        $updateParams = [$name];

                        if (array_key_exists('type', $original)) {
                            $updateParts[] = "type = ?";
                            $updateParams[] = $typeValue !== '' ? $typeValue : null;
                        } elseif (array_key_exists('category', $original)) {
                            $updateParts[] = "category = ?";
                            $updateParams[] = $typeValue !== '' ? $typeValue : ($original['category'] ?? null);
                        }

                        if (array_key_exists('unit', $original)) {
                            $updateParts[] = "unit = ?";
                            $updateParams[] = $unitValue !== '' ? $unitValue : null;
                        }

                        if (array_key_exists('specifications', $original)) {
                            $updateParts[] = "specifications = ?";
                            $updateParams[] = $specificationsValue !== '' ? $specificationsValue : null;
                        }

                        if (array_key_exists('status', $original)) {
                            $updateParts[] = "status = ?";
                            $updateParams[] = $statusValue;
                        }

                        if (array_key_exists('updated_at', $original)) {
                            $updateParts[] = "updated_at = NOW()";
                        }

                        if (empty($updateParts)) {
                            throw new Exception('لا توجد حقول متاحة للتعديل على هذا المنتج.');
                        }

                        $updateQuery = "UPDATE products SET " . implode(', ', $updateParts) . " WHERE id = ?";
                        $updateParams[] = $materialId;

                        $db->execute($updateQuery, $updateParams);
                    }

                    logAudit(
                        $currentUser['id'],
                        'update_packaging_material',
                        $usePackagingTable ? 'packaging_materials' : 'products',
                        $materialId,
                        $original ?? null,
                        [
                            'name' => $name,
                            'type' => $typeValue,
                            'unit' => $unitValue,
                            'material_id' => $materialCode,
                            'status' => $statusValue
                        ]
                    );

                    $db->commit();

                    $successMessage = 'تم تحديث بيانات أداة التعبئة بنجاح.';
                    $redirectParams = ['page' => 'packaging_warehouse'];
                    foreach (['search', 'material_id', 'date_from', 'date_to'] as $param) {
                        if (!empty($_GET[$param])) {
                            $redirectParams[$param] = $_GET[$param];
                        }
                    }

                    preventDuplicateSubmission($successMessage, $redirectParams, null, $currentUser['role']);
                } catch (Throwable $updateError) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = 'حدث خطأ أثناء تعديل بيانات الأداة: ' . $updateError->getMessage();
                }
            }
        }
    }
}

// تحميل قائمة أدوات التعبئة بعد معالجة الطلبات
if ($usePackagingTable) {
    $packagingMaterials = $db->query(
        "SELECT id, material_id, name, alias, type, specifications, quantity, unit, status, created_at, updated_at
         FROM packaging_materials 
         WHERE status = 'active'
         ORDER BY name"
    );
} else {
    $typeColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'type'");
    $specificationsColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'specifications'");
    $unitColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'unit'");
    $hasTypeColumn = !empty($typeColumnCheck);
    $hasSpecificationsColumn = !empty($specificationsColumnCheck);

    $columns = ['id', 'name', 'category', 'quantity'];
    if ($hasTypeColumn) {
        $columns[] = 'type';
    }
    if ($hasSpecificationsColumn) {
        $columns[] = 'specifications';
    }
    if ($unitColumnCheck) {
        $columns[] = 'unit';
    }

    $whereConditions = ["(category LIKE '%تغليف%' OR category LIKE '%packaging%'"];
    if ($hasTypeColumn) {
        $whereConditions[0] .= " OR type LIKE '%تغليف%'";
    }
    $whereConditions[0] .= ") AND status = 'active'";

    $packagingMaterials = $db->query(
        "SELECT " . implode(', ', $columns) . " FROM products 
         WHERE " . implode(' AND ', $whereConditions) . "
         ORDER BY name"
    );
    foreach ($packagingMaterials as &$legacyMaterial) {
        if (!array_key_exists('alias', $legacyMaterial)) {
            $legacyMaterial['alias'] = null;
        }
    }
    unset($legacyMaterial);
}

$packagingTypeOptions = getPackagingTypeOptions($db, $usePackagingTable);
$nextMaterialCode = generateNextPackagingMaterialCode($db, $usePackagingTable);

if (isset($_GET['ajax']) && $_GET['ajax'] === 'next_code') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $code = $nextMaterialCode;
        $attempts = 0;
        while (packagingMaterialCodeExists($db, $code, $usePackagingTable) && $attempts < 5) {
            $code = generateNextPackagingMaterialCode($db, $usePackagingTable);
            $attempts++;
        }
        echo json_encode([
            'success' => true,
            'code' => $code
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ajaxError) {
        echo json_encode([
            'success' => false,
            'message' => 'تعذر توليد كود جديد: ' . $ajaxError->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// بناء استعلام للحصول على الاستخدامات
$materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
$productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
$hasMaterialIdColumn = !empty($materialIdColumnCheck);
$hasProductIdColumn = !empty($productIdColumnCheck);
$materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);

// التحقق من عمود date في جدول production
$productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
$productionDateColumnCheck2 = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
$productionDateColumn = !empty($productionDateColumnCheck) ? 'date' : (!empty($productionDateColumnCheck2) ? 'production_date' : 'created_at');

// معالجة AJAX لعرض التفاصيل - يجب أن يكون في بداية الملف قبل أي محتوى HTML
if (isset($_GET['ajax']) && isset($_GET['material_id'])) {
    // بدء output buffering لمنع أي إخراج غير مرغوب فيه
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    try {
        $materialId = intval($_GET['material_id']);
        
        if ($materialId <= 0) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'معرف المادة غير صحيح'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $materialRow = $usePackagingTable
            ? $db->queryOne("SELECT * FROM packaging_materials WHERE id = ?", [$materialId])
            : $db->queryOne("SELECT * FROM products WHERE id = ?", [$materialId]);

        if (!$materialRow) {
            ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'أداة التعبئة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $materialData = [
            'id' => intval($materialRow['id'] ?? $materialId),
            'material_id' => $materialRow['material_id'] ?? null,
            'name' => $materialRow['name'] ?? '',
            'alias' => $materialRow['alias'] ?? null,
            'type' => $materialRow['type'] ?? null,
            'category' => $materialRow['category'] ?? null,
            'specifications' => $materialRow['specifications'] ?? '',
            'unit' => $materialRow['unit'] ?? '',
            'quantity' => isset($materialRow['quantity']) ? floatval($materialRow['quantity']) : null,
            'status' => $materialRow['status'] ?? 'active',
        ];

        foreach (['supplier_id', 'reorder_point', 'lead_time_days', 'unit_price'] as $optionalKey) {
            if (array_key_exists($optionalKey, $materialRow)) {
                $materialData[$optionalKey] = $materialRow[$optionalKey];
            }
        }

        if (empty($materialData['type']) && !empty($materialData['category'])) {
            $materialData['type'] = $materialData['category'];
        }

        // التحقق من وجود جدول production_materials
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'production_materials'");
        
        $productions = [];
        if (!empty($tableCheck)) {
            $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
            $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
            $hasMaterialIdColumn = !empty($materialIdColumnCheck);
            $hasProductIdColumn = !empty($productIdColumnCheck);
            $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
            
            if ($materialColumn) {
                $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
                $productionDateColumnCheck2 = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
                $productionDateColumn = !empty($productionDateColumnCheck) ? 'date' : (!empty($productionDateColumnCheck2) ? 'production_date' : 'created_at');
                
                try {
                    $productions = $db->query(
                        "SELECT 
                            pm.production_id,
                            pm.quantity_used,
                            p.{$productionDateColumn} as date,
                            pr.name as product_name
                         FROM production_materials pm
                         LEFT JOIN production p ON pm.production_id = p.id
                         LEFT JOIN products pr ON p.product_id = pr.id
                         WHERE pm.{$materialColumn} = ?
                         ORDER BY p.{$productionDateColumn} DESC
                         LIMIT 50",
                        [$materialId]
                    );
                } catch (Exception $queryError) {
                    error_log("Error querying production_materials: " . $queryError->getMessage());
                    $productions = [];
                }
            }
        }
        
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'material' => $materialData,
            'productions' => $productions ?: []
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        ob_clean();
        error_log("Error in AJAX material details: " . $e->getMessage());
        error_log("Error stack trace: " . $e->getTraceAsString());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'حدث خطأ في تحميل البيانات: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// الحصول على استخدامات أدوات التعبئة
$usageData = [];
$usageDataHasLogs = false;

if ($hasPackagingUsageLogs) {
    try {
        $usageLogRows = $db->query(
            "SELECT 
                material_id,
                source_table,
                SUM(quantity_used) AS total_used,
                COUNT(*) AS usage_count,
                MIN(created_at) AS first_used,
                MAX(created_at) AS last_used
             FROM packaging_usage_logs
             GROUP BY material_id, source_table"
        );

        foreach ($usageLogRows as $logRow) {
            $materialId = isset($logRow['material_id']) ? (int) $logRow['material_id'] : 0;
            if ($materialId <= 0) {
                continue;
            }

            $sourceTable = $logRow['source_table'] ?? ($usePackagingTable ? 'packaging_materials' : 'products');
            if ($usePackagingTable && $sourceTable !== 'packaging_materials') {
                continue;
            }
            if (!$usePackagingTable && $sourceTable !== 'products') {
                continue;
            }

            $usageData[$materialId] = [
                'total_used' => max(0.0, (float)($logRow['total_used'] ?? 0)),
                'production_count' => 0, // سيتم تحديثه من production_materials
                'first_used' => $logRow['first_used'] ?? null,
                'last_used' => $logRow['last_used'] ?? null
            ];
        }
    } catch (Exception $usageLogError) {
        error_log("Packaging usage logs aggregation error: " . $usageLogError->getMessage());
        $usageData = [];
    }

    $usageDataHasLogs = !empty($usageData);
}

if ($materialColumn) {
    if ($usePackagingTable) {
        // إذا كنا نستخدم packaging_materials، نحتاج لربط material_id من packaging_materials
        // أولاً: إنشاء mapping من packaging_materials.id إلى id
        $pkgMaterialsMap = [];
        foreach ($packagingMaterials as $pkg) {
            $pkgMaterialsMap[$pkg['id']] = $pkg['id'];
        }
        
        // ثم البحث عن الاستخدامات بناءً على packaging_materials.id
        $usageQuery = "
            SELECT 
                pm.{$materialColumn} as material_id,
                SUM(pm.quantity_used) as total_used,
                COUNT(DISTINCT pm.production_id) as production_count,
                MIN(p.{$productionDateColumn}) as first_used,
                MAX(p.{$productionDateColumn}) as last_used
            FROM production_materials pm
            LEFT JOIN production p ON pm.production_id = p.id
            WHERE pm.{$materialColumn} IS NOT NULL
            GROUP BY pm.{$materialColumn}
        ";
        
        $usageResults = $db->query($usageQuery);
        foreach ($usageResults as $usage) {
            $materialId = isset($usage['material_id']) ? (int) $usage['material_id'] : 0;
            if ($materialId <= 0) {
                continue;
            }

            $pkgMaterial = $db->queryOne(
                "SELECT id FROM packaging_materials WHERE id = ? OR material_id LIKE ?",
                [$materialId, '%' . $materialId . '%']
            );

            $mappedId = $pkgMaterial ? (int) $pkgMaterial['id'] : $materialId;

            if (isset($usageData[$mappedId])) {
                // تحديث production_count دائماً من production_materials (الحساب الصحيح)
                $usageData[$mappedId]['production_count'] = max(0, (int)($usage['production_count'] ?? 0));
                
                // تحديث total_used فقط إذا لم تكن هناك سجلات (لأن السجلات قد تكون أكثر دقة)
                if (!$usageDataHasLogs) {
                    $usageData[$mappedId]['total_used'] += (float)($usage['total_used'] ?? 0);
                }

                $firstUsed = $usage['first_used'] ?? null;
                $lastUsed = $usage['last_used'] ?? null;

                if ($firstUsed && (empty($usageData[$mappedId]['first_used']) || $firstUsed < $usageData[$mappedId]['first_used'])) {
                    $usageData[$mappedId]['first_used'] = $firstUsed;
                }
                if ($lastUsed && (empty($usageData[$mappedId]['last_used']) || $lastUsed > $usageData[$mappedId]['last_used'])) {
                    $usageData[$mappedId]['last_used'] = $lastUsed;
                }

                continue;
            }

            $usageData[$mappedId] = [
                'total_used' => max(0.0, (float)($usage['total_used'] ?? 0)),
                'production_count' => max(0, (int)($usage['production_count'] ?? 0)),
                'first_used' => $usage['first_used'] ?? null,
                'last_used' => $usage['last_used'] ?? null
            ];
        }
    } else {
        // استخدام products (الطريقة القديمة)
        $usageQuery = "
            SELECT 
                pm.{$materialColumn} as material_id,
                SUM(pm.quantity_used) as total_used,
                COUNT(DISTINCT pm.production_id) as production_count,
                MIN(p.{$productionDateColumn}) as first_used,
                MAX(p.{$productionDateColumn}) as last_used
            FROM production_materials pm
            LEFT JOIN production p ON pm.production_id = p.id
            WHERE pm.{$materialColumn} IS NOT NULL
            GROUP BY pm.{$materialColumn}
        ";
        
        $usageResults = $db->query($usageQuery);
        foreach ($usageResults as $usage) {
            $materialId = isset($usage['material_id']) ? (int) $usage['material_id'] : 0;
            if ($materialId <= 0) {
                continue;
            }

            if (isset($usageData[$materialId])) {
                // تحديث production_count دائماً من production_materials (الحساب الصحيح)
                $usageData[$materialId]['production_count'] = max(0, (int)($usage['production_count'] ?? 0));
                
                // تحديث total_used فقط إذا لم تكن هناك سجلات (لأن السجلات قد تكون أكثر دقة)
                if (!$usageDataHasLogs) {
                    $usageData[$materialId]['total_used'] += (float)($usage['total_used'] ?? 0);
                }

                $firstUsed = $usage['first_used'] ?? null;
                $lastUsed = $usage['last_used'] ?? null;

                if ($firstUsed && (empty($usageData[$materialId]['first_used']) || $firstUsed < $usageData[$materialId]['first_used'])) {
                    $usageData[$materialId]['first_used'] = $firstUsed;
                }
                if ($lastUsed && (empty($usageData[$materialId]['last_used']) || $lastUsed > $usageData[$materialId]['last_used'])) {
                    $usageData[$materialId]['last_used'] = $lastUsed;
                }

                continue;
            }

            $usageData[$materialId] = [
                'total_used' => max(0.0, (float)($usage['total_used'] ?? 0)),
                'production_count' => max(0, (int)($usage['production_count'] ?? 0)),
                'first_used' => $usage['first_used'] ?? null,
                'last_used' => $usage['last_used'] ?? null
            ];
        }
    }
}

// الحصول على استخدامات من batch_numbers (باستخدام PHP لمعالجة JSON)
try {
    $batches = $db->query(
        "SELECT id, packaging_materials, quantity, production_date 
         FROM batch_numbers 
         WHERE packaging_materials IS NOT NULL 
         AND packaging_materials != 'null' 
         AND packaging_materials != ''
         AND packaging_materials != '[]'"
    );
    
    foreach ($batches as $batch) {
        $materials = json_decode($batch['packaging_materials'], true);
        if (is_array($materials) && !empty($materials)) {
            foreach ($materials as $materialId) {
                $materialId = intval($materialId);
                if ($materialId > 0) {
                    // إذا كنا نستخدم packaging_materials، نحتاج لربط material_id
                    if ($usePackagingTable) {
                        // البحث عن packaging_material_id من material_id
                        $pkgMaterial = $db->queryOne(
                            "SELECT id FROM packaging_materials WHERE material_id = ? OR id = ?",
                            [$materialId, $materialId]
                        );
                        if ($pkgMaterial) {
                            $materialId = $pkgMaterial['id'];
                        }
                    }
                    
                    if (isset($usageData[$materialId])) {
                        if ($usageDataHasLogs) {
                            continue;
                        }

                        $usageData[$materialId]['total_used'] += (float)$batch['quantity'];
                        $usageData[$materialId]['production_count'] += 1;

                        if (empty($usageData[$materialId]['first_used']) || $batch['production_date'] < $usageData[$materialId]['first_used']) {
                            $usageData[$materialId]['first_used'] = $batch['production_date'];
                        }
                        if (empty($usageData[$materialId]['last_used']) || $batch['production_date'] > $usageData[$materialId]['last_used']) {
                            $usageData[$materialId]['last_used'] = $batch['production_date'];
                        }
                    } else {
                        $usageData[$materialId] = [
                            'total_used' => (float)$batch['quantity'],
                            'production_count' => 1,
                            'first_used' => $batch['production_date'],
                            'last_used' => $batch['production_date']
                        ];
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    // إذا فشل الاستعلام، تجاهل
    error_log("Batch usage processing error: " . $e->getMessage());
}

if ($hasPackagingUsageLogs && !$usageDataHasLogs) {
    try {
        $manualUsageRows = $db->query(
            "SELECT material_id, source_table, 
                    SUM(quantity_used) AS total_used, 
                    COUNT(*) AS usage_count,
                    MIN(created_at) AS first_used,
                    MAX(created_at) AS last_used
             FROM packaging_usage_logs
             GROUP BY material_id, source_table"
        );

        foreach ($manualUsageRows as $manualUsage) {
            $manualSource = $manualUsage['source_table'] ?? 'packaging_materials';
            if ($usePackagingTable) {
                if ($manualSource !== 'packaging_materials') {
                    continue;
                }
            } else {
                if ($manualSource !== 'products') {
                    continue;
                }
            }

            $manualMaterialId = intval($manualUsage['material_id'] ?? 0);
            if ($manualMaterialId <= 0) {
                continue;
            }

            if (!isset($usageData[$manualMaterialId])) {
                $usageData[$manualMaterialId] = [
                    'total_used' => 0,
                    'production_count' => 0,
                    'first_used' => null,
                    'last_used' => null
                ];
            }

            $usageData[$manualMaterialId]['total_used'] += (float)($manualUsage['total_used'] ?? 0);
            // لا نستخدم usage_count من السجلات كـ production_count لأنه يحسب سجلات وليس عمليات إنتاج
            // production_count سيتم تحديثه من production_materials

            $manualFirstUsed = $manualUsage['first_used'] ?? null;
            $manualLastUsed = $manualUsage['last_used'] ?? null;

            if ($manualFirstUsed !== null) {
                $currentFirst = $usageData[$manualMaterialId]['first_used'];
                if (empty($currentFirst) || $manualFirstUsed < $currentFirst) {
                    $usageData[$manualMaterialId]['first_used'] = $manualFirstUsed;
                }
            }

            if ($manualLastUsed !== null) {
                $currentLast = $usageData[$manualMaterialId]['last_used'];
                if (empty($currentLast) || $manualLastUsed > $currentLast) {
                    $usageData[$manualMaterialId]['last_used'] = $manualLastUsed;
                }
            }
        }
    } catch (Exception $manualUsageError) {
        error_log('Manual packaging usage aggregation error: ' . $manualUsageError->getMessage());
    }
}

// تطبيق الفلاتر
$filteredMaterials = [];
foreach ($packagingMaterials as $material) {
    $materialId = $material['id'];
    
    // فلترة البحث
    if (!empty($filters['search'])) {
        $search = strtolower($filters['search']);
        $name = strtolower($material['name'] ?? '');
        $category = strtolower($material['category'] ?? '');
        $type = strtolower($material['type'] ?? '');
        $specifications = strtolower($material['specifications'] ?? '');
        $materialIdStr = strtolower($material['material_id'] ?? '');
        $aliasValue = strtolower($material['alias'] ?? '');
        
        if (strpos($name, $search) === false && 
            strpos($category, $search) === false &&
            strpos($type, $search) === false &&
            strpos($specifications, $search) === false &&
            strpos($materialIdStr, $search) === false &&
            strpos($aliasValue, $search) === false) {
            continue;
        }
    }
    
    // فلترة حسب المادة
    if ($filters['material_id'] > 0 && $materialId != $filters['material_id']) {
        continue;
    }
    
    // فلترة حسب التاريخ
    if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
        $usage = $usageData[$materialId] ?? null;
        if (!$usage) {
            continue; // إذا لم تُستخدم، تخطيها
        }
        
        if (!empty($filters['date_from']) && $usage['last_used'] < $filters['date_from']) {
            continue;
        }
        if (!empty($filters['date_to']) && $usage['first_used'] > $filters['date_to']) {
            continue;
        }
    }
    
    $material['usage'] = $usageData[$materialId] ?? [
        'total_used' => 0,
        'production_count' => 0,
        'first_used' => null,
        'last_used' => null
    ];
    
    $filteredMaterials[] = $material;
}

// Pagination
$totalMaterials = count($filteredMaterials);
$totalPages = ceil($totalMaterials / $perPage);
$paginatedMaterials = array_slice($filteredMaterials, $offset, $perPage);

// حساب عدد عمليات الإنتاج المميزة (وليس مجموع production_count لكل مادة)
$totalProductionsCount = 0;
if ($materialColumn) {
    try {
        $totalProductionsResult = $db->queryOne(
            "SELECT COUNT(DISTINCT pm.production_id) as total_productions
             FROM production_materials pm
             WHERE pm.{$materialColumn} IS NOT NULL"
        );
        $totalProductionsCount = isset($totalProductionsResult['total_productions']) 
            ? (int)$totalProductionsResult['total_productions'] 
            : 0;
    } catch (Exception $e) {
        error_log("Error calculating total productions: " . $e->getMessage());
        // في حالة الخطأ، نستخدم المجموع كبديل
        $totalProductionsCount = array_sum(array_column($usageData, 'production_count'));
    }
} else {
    // إذا لم يكن هناك materialColumn، نستخدم المجموع
    $totalProductionsCount = array_sum(array_column($usageData, 'production_count'));
}

// إحصائيات
$stats = [
    'total_materials' => count($packagingMaterials),
    'total_used' => array_sum(array_column($usageData, 'total_used')),
    'materials_with_usage' => count($usageData),
    'total_productions' => $totalProductionsCount
];

$packagingReport = [
    'generated_at' => date('Y-m-d H:i'),
    'generated_by' => $currentUser['full_name'] ?? ($currentUser['username'] ?? 'مستخدم'),
    'total_materials' => count($packagingMaterials),
    'total_quantity' => 0,
    'type_breakdown' => [],
    'top_items' => [],
    'zero_quantity' => 0,
    'materials_with_usage' => $stats['materials_with_usage'],
    'total_used' => $stats['total_used'],
    'total_productions' => $stats['total_productions'],
    'last_updated' => null
];

$lastUpdatedTimestamp = null;

foreach ($packagingMaterials as $material) {
    $quantity = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
    $unit = trim((string)($material['unit'] ?? ''));
    if ($unit === '') {
        $unit = 'وحدة';
    }

    $typeLabel = trim((string)($material['type'] ?? ($material['category'] ?? '')));
    if ($typeLabel === '') {
        $typeLabel = 'غير مصنف';
    }

    $packagingReport['total_quantity'] += $quantity;
    if ($quantity <= 0) {
        $packagingReport['zero_quantity']++;
    }

    if (!isset($packagingReport['type_breakdown'][$typeLabel])) {
        $packagingReport['type_breakdown'][$typeLabel] = [
            'count' => 0,
            'total_quantity' => 0,
            'units' => []
        ];
    }

    $packagingReport['type_breakdown'][$typeLabel]['count']++;
    $packagingReport['type_breakdown'][$typeLabel]['total_quantity'] += $quantity;
    if (!isset($packagingReport['type_breakdown'][$typeLabel]['units'][$unit])) {
        $packagingReport['type_breakdown'][$typeLabel]['units'][$unit] = 0;
    }
    $packagingReport['type_breakdown'][$typeLabel]['units'][$unit] += $quantity;

    $materialName = $material['name'] ?? ('أداة #' . ($material['id'] ?? ''));
    $packagingReport['top_items'][] = [
        'name' => $materialName,
        'alias' => $material['alias'] ?? null,
        'type' => $typeLabel,
        'quantity' => $quantity,
        'unit' => $unit,
        'code' => $material['material_id'] ?? ($material['id'] ?? null)
    ];

    if (!empty($material['updated_at'])) {
        $timestamp = strtotime((string)$material['updated_at']);
        if ($timestamp !== false && ($lastUpdatedTimestamp === null || $timestamp > $lastUpdatedTimestamp)) {
            $lastUpdatedTimestamp = $timestamp;
        }
    } elseif (!empty($material['created_at'])) {
        $timestamp = strtotime((string)$material['created_at']);
        if ($timestamp !== false && ($lastUpdatedTimestamp === null || $timestamp > $lastUpdatedTimestamp)) {
            $lastUpdatedTimestamp = $timestamp;
        }
    }
}

$packagingReport['types_count'] = count($packagingReport['type_breakdown']);

foreach ($packagingReport['type_breakdown'] as $typeKey => &$breakdownEntry) {
    $count = max(1, (int)$breakdownEntry['count']);
    $breakdownEntry['average_quantity'] = $breakdownEntry['total_quantity'] / $count;
}
unset($breakdownEntry);

ksort($packagingReport['type_breakdown'], SORT_NATURAL | SORT_FLAG_CASE);

// ترتيب الأدوات حسب الكمية (الأعلى أولاً للعرض العادي)
usort($packagingReport['top_items'], static function ($a, $b) {
    return $b['quantity'] <=> $a['quantity'];
});
// نحتفظ بجميع الأدوات في التقرير (وليس فقط أعلى 8) حتى يمكن تصفيتها عند الطباعة
// $packagingReport['top_items'] = array_slice($packagingReport['top_items'], 0, 8);

$packagingReport['last_updated'] = $lastUpdatedTimestamp
    ? date('Y-m-d H:i', $lastUpdatedTimestamp)
    : null;

$packagingReportMeta = storePackagingReportDocument($packagingReport);
$packagingReportViewUrl = $packagingReportMeta['viewer_path'] ?? '';
$packagingReportPrintUrl = $packagingReportMeta['print_path'] ?? '';
$packagingReportAbsoluteView = $packagingReportMeta['absolute_viewer_url'] ?? '';
$packagingReportAbsolutePrint = $packagingReportMeta['absolute_print_url'] ?? '';
$packagingReportGeneratedAt = $packagingReport['generated_at'] ?? date('Y-m-d H:i');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h2 class="mb-0"><i class="bi bi-box-seam me-2"></i>مخزن أدوات التعبئة</h2>
    <div class="d-flex flex-wrap gap-2">
        <?php if (($currentUser['role'] ?? '') === 'manager'): ?>
            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#createMaterialModal"
            >
                <i class="bi bi-plus-circle me-1"></i>
                إضافة أداة جديدة
            </button>
        <?php endif; ?>
        <button
            type="button"
            class="btn btn-outline-secondary"
            id="generatePackagingReportBtn"
            data-viewer-url="<?php echo htmlspecialchars((string)$packagingReportAbsoluteView, ENT_QUOTES, 'UTF-8'); ?>"
            data-print-url="<?php echo htmlspecialchars((string)$packagingReportAbsolutePrint, ENT_QUOTES, 'UTF-8'); ?>"
            data-report-ready="<?php echo $packagingReportViewUrl !== '' ? '1' : '0'; ?>"
            data-generated-at="<?php echo htmlspecialchars((string)$packagingReportGeneratedAt, ENT_QUOTES, 'UTF-8'); ?>"
        >
            <i class="bi bi-file-bar-graph me-1"></i>
            انشاء تقرير المخزن
        </button>
    </div>
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

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">إجمالي الأدوات</div>
                        <div class="h4 mb-0"><?php echo $stats['total_materials']; ?></div>
                    </div>
                    <div class="text-primary">
                        <i class="bi bi-box-seam fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">أدوات مستخدمة</div>
                        <div class="h4 mb-0"><?php echo $stats['materials_with_usage']; ?></div>
                    </div>
                    <div class="text-info">
                        <i class="bi bi-check-circle fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">عمليات الإنتاج</div>
                        <div class="h4 mb-0"><?php echo $stats['total_productions']; ?></div>
                    </div>
                    <div class="text-success">
                        <i class="bi bi-gear fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- البحث والفلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="page" value="packaging_warehouse">
            <div class="col-md-4">
                <label class="form-label">البحث</label>
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($filters['search']); ?>" 
                       placeholder="اسم أو فئة...">
            </div>
            <div class="col-md-3">
                <label class="form-label">أداة محددة</label>
                <select class="form-select" name="material_id">
                    <option value="0">جميع الأدوات</option>
                    <?php 
                    require_once __DIR__ . '/../../includes/path_helper.php';
                    $selectedMaterialId = $filters['material_id'];
                    $materialValid = isValidSelectValue($selectedMaterialId, $packagingMaterials, 'id');
                    foreach ($packagingMaterials as $mat): ?>
                        <option value="<?php echo $mat['id']; ?>" 
                                <?php echo $materialValid && $selectedMaterialId == $mat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($mat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">من تاريخ</label>
                <input type="date" class="form-control" name="date_from" 
                       value="<?php echo htmlspecialchars($filters['date_from']); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">إلى تاريخ</label>
                <input type="date" class="form-control" name="date_to" 
                       value="<?php echo htmlspecialchars($filters['date_to']); ?>">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة أدوات التعبئة -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <h5 class="mb-0">قائمة أدوات التعبئة (<?php echo $totalMaterials; ?>)</h5>
        
    </div>
    <div class="card-body">
        <?php if (empty($paginatedMaterials)): ?>
            <div class="text-center text-muted py-4">لا توجد أدوات تعبئة</div>
        <?php else: ?>
            <!-- عرض الجدول على الشاشات الكبيرة -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-striped table-sm packaging-table" style="font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="width: 40px; padding: 0.5rem 0.25rem;">#</th>
                            <th style="padding: 0.5rem 0.25rem;">اسم الأداة</th>
                            <th style="width: 100px; padding: 0.5rem 0.25rem;">الفئة</th>
                            <th style="width: 120px; padding: 0.5rem 0.25rem;">الكمية المتاحة</th>
                            <th style="width: 80px; padding: 0.5rem 0.25rem;">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginatedMaterials as $index => $material): ?>
                            <tr>
                                <td style="padding: 0.4rem 0.25rem;"><?php echo $offset + $index + 1; ?></td>
                                <td style="padding: 0.4rem 0.25rem; line-height: 1.3;">
                                    <div style="font-weight: 600; font-size: 0.875rem;"><?php echo htmlspecialchars($material['name']); ?></div>
                                    <?php 
                                        $aliasValue = trim((string)($material['alias'] ?? ''));
                                        $aliasDisplayText = $aliasValue !== '' ? $aliasValue : 'لا يوجد اسم مستعار';
                                        $aliasDisplayClass = $aliasValue !== '' ? 'text-info' : 'text-muted';
                                    ?>
                                    <div class="alias-display <?php echo $aliasDisplayClass; ?>" data-empty-text="لا يوجد اسم مستعار" style="font-size: 0.75rem; margin-top: 2px;">
                                        <span class="fw-semibold text-secondary">الاسم المستعار:</span>
                                        <span class="alias-text"><?php echo htmlspecialchars($aliasDisplayText); ?></span>
                                    </div>
                                    <?php if (!empty($material['specifications'])): ?>
                                        <div style="font-size: 0.75rem; color: #6c757d; margin-top: 2px;"><?php echo htmlspecialchars($material['specifications']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($material['material_id'])): ?>
                                        <div style="font-size: 0.7rem; color: #0dcaf0; margin-top: 2px;"><?php echo htmlspecialchars($material['material_id']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($usePackagingTable): ?>
                                        <form class="alias-inline-form mt-2" data-material-id="<?php echo $material['id']; ?>">
                                            <div class="input-group input-group-sm alias-input-group">
                                                <input type="text"
                                                       name="alias"
                                                       class="form-control form-control-sm"
                                                       placeholder="اكتب الاسم المستعار"
                                                       value="<?php echo htmlspecialchars($aliasValue, ENT_QUOTES, 'UTF-8'); ?>"
                                                       maxlength="255">
                                                <button class="btn btn-outline-secondary" type="submit">
                                                    حفظ
                                                </button>
                                            </div>
                                            <div class="alias-status small text-muted mt-1" role="status"></div>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.4rem 0.25rem; font-size: 0.8rem;"><?php echo htmlspecialchars($material['type'] ?? $material['category'] ?? '-'); ?></td>
                                <?php
                                    $materialQuantity = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
                                    $useButtonDisabled = $materialQuantity <= 0;
                                    $quantityElementId = 'material-quantity-' . $material['id'];
                                ?>
                                <td style="padding: 0.4rem 0.25rem;">
                                    <div
                                        id="<?php echo htmlspecialchars($quantityElementId, ENT_QUOTES, 'UTF-8'); ?>"
                                        style="font-weight: 600; font-size: 0.875rem;"
                                        class="text-<?php echo $materialQuantity > 0 ? 'success' : 'danger'; ?>"
                                        data-value="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                        data-unit-label="<?php echo htmlspecialchars($material['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-append-unit="0">
                                        <?php echo number_format($materialQuantity, 2); ?>
                                    </div>
                                    <?php if (!empty($material['unit'])): ?>
                                        <div style="font-size: 0.7rem; color: #6c757d;"><?php echo htmlspecialchars($material['unit']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.4rem 0.25rem;">
                                    <div class="btn-group btn-group-sm">
                                        <button
                                            class="btn btn-outline-primary btn-sm"
                                            type="button"
                                            data-id="<?php echo $material['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-quantity="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                            data-quantity-target="<?php echo htmlspecialchars($quantityElementId, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-use-quantity="1"
                                            onclick="usePackagingMaterial(this)"
                                            title="استخدام وحدة واحدة"
                                            style="padding: 0.2rem 0.4rem; font-size: 0.75rem;"
                                            <?php echo $useButtonDisabled ? ' disabled' : ''; ?>
                                            aria-label="استخدام الأداة">
                                            <span class="visually-hidden">استخدام</span>
                                            <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                        </button>
                                        <button class="btn btn-success btn-sm"
                                                data-id="<?php echo $material['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quantity="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                                onclick="openAddQuantityModal(this)"
                                                title="إضافة كمية"
                                                style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm"
                                                data-id="<?php echo $material['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                                data-quantity="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                                onclick="openRecordDamageModal(this)"
                                                title="تسجيل تالف"
                                                style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">
                                            <i class="bi bi-exclamation-octagon"></i>
                                        </button>
                                        <?php if ($currentUser['role'] === 'manager'): ?>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="editMaterial(<?php echo $material['id']; ?>)"
                                                    title="تعديل"
                                                    style="padding: 0.2rem 0.4rem; font-size: 0.75rem;">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- عرض Cards على الموبايل -->
            <div class="d-md-none">
                <?php foreach ($paginatedMaterials as $index => $material): ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($material['name']); ?></h6>
                                    <?php 
                                        $aliasValue = trim((string)($material['alias'] ?? ''));
                                        $aliasDisplayText = $aliasValue !== '' ? $aliasValue : 'لا يوجد اسم مستعار';
                                        $aliasDisplayClass = $aliasValue !== '' ? 'text-info' : 'text-muted';
                                    ?>
                                    <div class="alias-display <?php echo $aliasDisplayClass; ?> small mb-1" data-empty-text="لا يوجد اسم مستعار">
                                        <span class="fw-semibold text-secondary">الاسم المستعار:</span>
                                        <span class="alias-text"><?php echo htmlspecialchars($aliasDisplayText); ?></span>
                                    </div>
                                    <?php if ($usePackagingTable): ?>
                                        <form class="alias-inline-form alias-mobile-form mt-2" data-material-id="<?php echo $material['id']; ?>">
                                            <div class="input-group input-group-sm alias-input-group mb-1">
                                                <input type="text"
                                                       name="alias"
                                                       class="form-control form-control-sm"
                                                       placeholder="اسم مستعار"
                                                       value="<?php echo htmlspecialchars($aliasValue, ENT_QUOTES, 'UTF-8'); ?>"
                                                       maxlength="255">
                                                <button class="btn btn-outline-secondary" type="submit">
                                                    حفظ
                                                </button>
                                            </div>
                                            <div class="alias-status small text-muted mb-2" role="status"></div>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (!empty($material['specifications'])): ?>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($material['specifications']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($material['material_id'])): ?>
                                        <small class="text-info d-block"><?php echo htmlspecialchars($material['material_id']); ?></small>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-primary">#<?php echo $offset + $index + 1; ?></span>
                            </div>
                            
                            <?php
                                $materialQuantity = isset($material['quantity']) ? (float)$material['quantity'] : 0.0;
                                $useButtonDisabled = $materialQuantity < 1;
                                $mobileQuantityElementId = 'material-quantity-' . $material['id'] . '-mobile';
                            ?>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">الفئة:</small>
                                    <strong><?php echo htmlspecialchars($material['type'] ?? $material['category'] ?? '-'); ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">الكمية:</small>
                                    <strong
                                        id="<?php echo htmlspecialchars($mobileQuantityElementId, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="text-<?php echo $materialQuantity > 0 ? 'success' : 'danger'; ?>"
                                        data-value="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                        data-unit-label="<?php echo htmlspecialchars($material['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-append-unit="1">
                                        <?php echo number_format($materialQuantity, 2); ?>
                                        <?php echo htmlspecialchars($material['unit'] ?? ''); ?>
                                    </strong>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-12">
                                    <small class="text-muted d-block">المستخدم:</small>
                                    <strong class="text-warning"><?php echo number_format($material['usage']['total_used'] ?? 0, 2); ?></strong>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-flex mt-3">
                                <button
                                    class="btn btn-sm btn-outline-primary flex-fill"
                                    type="button"
                                    data-id="<?php echo $material['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                    data-quantity="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                    data-quantity-target="<?php echo htmlspecialchars($mobileQuantityElementId, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-use-quantity="1"
                                    onclick="usePackagingMaterial(this)"
                                    <?php echo $useButtonDisabled ? ' disabled' : ''; ?>>
                                    <i class="bi bi-check2-circle me-2" aria-hidden="true"></i>
                                    <span>استخدام</span>
                                </button>
                                <button class="btn btn-sm btn-success flex-fill"
                                        data-id="<?php echo $material['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantity="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                        onclick="openAddQuantityModal(this)">
                                    <i class="bi bi-plus-circle me-2"></i>إضافة كمية
                                </button>
                                <button class="btn btn-sm btn-danger flex-fill"
                                        data-id="<?php echo $material['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($material['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-unit="<?php echo htmlspecialchars(!empty($material['unit']) ? $material['unit'] : 'وحدة', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-quantity="<?php echo number_format($materialQuantity, 4, '.', ''); ?>"
                                        onclick="openRecordDamageModal(this)">
                                    <i class="bi bi-exclamation-octagon me-2"></i>تسجيل تالف
                                </button>
                                <?php if ($currentUser['role'] === 'manager'): ?>
                                    <button class="btn btn-sm btn-warning flex-fill" onclick="editMaterial(<?php echo $material['id']; ?>)">
                                        <i class="bi bi-pencil me-2"></i>تعديل
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=packaging_warehouse&p=<?php echo $pageNum - 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=packaging_warehouse&p=1&<?php echo http_build_query($filters); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=packaging_warehouse&p=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=packaging_warehouse&p=<?php echo $totalPages; ?>&<?php echo http_build_query($filters); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=packaging_warehouse&p=<?php echo $pageNum + 1; ?>&<?php echo http_build_query($filters); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php if (($currentUser['role'] ?? '') === 'manager'): ?>
<div class="modal fade" id="createMaterialModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="createMaterialForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة أداة تعبئة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_packaging_material">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">كود الأداة <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text"
                                       class="form-control"
                                       id="create_material_code_display"
                                       value="<?php echo htmlspecialchars($nextMaterialCode, ENT_QUOTES, 'UTF-8'); ?>"
                                       readonly>
                                <button type="button"
                                        class="btn btn-outline-secondary"
                                        id="refresh_material_code_btn"
                                        title="تحديث الكود">
                                    <i class="bi bi-arrow-repeat"></i>
                                </button>
                            </div>
                            <input type="hidden"
                                   name="material_id"
                                   id="create_material_code"
                                   value="<?php echo htmlspecialchars($nextMaterialCode, ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="text-muted">يتم إنشاء الكود تلقائياً لضمان عدم تكراره.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">اسم الأداة <span class="text-danger">*</span></label>
                            <input type="text"
                                   class="form-control"
                                   name="name"
                                   required
                                   maxlength="255"
                                   placeholder="اسم الأداة">
                        </div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label">الفئة / النوع</label>
                            <select class="form-select" name="type" id="create_material_type">
                                <option value="">اختر النوع</option>
                                <?php foreach ($packagingTypeOptions as $typeOption): ?>
                                    <option value="<?php echo htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($typeOption, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($packagingTypeOptions)): ?>
                                <small class="text-muted">لم يتم العثور على أنواع مسجلة. يرجى إضافة الأنواع من جدول أنواع الأدوات.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status">
                                <option value="active" selected>نشطة</option>
                                <option value="inactive">غير نشطة</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label">الوحدة</label>
                            <div class="form-control-plaintext fw-semibold">قطعة</div>
                            <input type="hidden" name="unit" value="قطعة">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الكمية الابتدائية</label>
                            <div class="input-group">
                                <input type="number"
                                       class="form-control"
                                       name="initial_quantity"
                                       step="0.01"
                                       min="0"
                                       value="0"
                                       placeholder="0.00">
                                <span class="input-group-text">قطعة</span>
                            </div>
                            <small class="text-muted">يمكن تعديل الكمية لاحقاً من خلال زر "إضافة كمية".</small>
                        </div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label">الاسم المستعار (اختياري)</label>
                            <input type="text"
                                   class="form-control"
                                   name="alias"
                                   maxlength="255"
                                   placeholder="اسم مختصر للأداة">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>حفظ الأداة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="packagingReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-data me-2"></i>خيارات تقرير مخزن أدوات التعبئة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <?php if ($packagingReportViewUrl): ?>
                    <p class="mb-3 text-muted">
                        تم حفظ نسخة من التقرير في مساحة التخزين الآمنة بتاريخ
                        <span class="fw-semibold"><?php echo htmlspecialchars($packagingReportGeneratedAt, ENT_QUOTES, 'UTF-8'); ?></span>.
                        اختر الإجراء المطلوب أدناه.
                    </p>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary" id="packagingReportViewBtn">
                            <i class="bi bi-display me-2"></i>
                            عرض التقرير داخل المتصفح
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="packagingReportPrintBtn">
                            <i class="bi bi-printer me-2"></i>
                            طباعة / حفظ التقرير كـ PDF
                        </button>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="bi bi-shield-lock me-1"></i>
                        يتم فتح التقرير عبر `view.php` لضمان الحماية ومنع خطأ Forbidden.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        تعذّر حفظ التقرير تلقائياً. يرجى التأكد من صلاحيات الكتابة على مجلد التخزين ثم تحديث الصفحة.
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addQuantityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="addQuantityForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة كمية لأداة التعبئة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_packaging_quantity">
                    <input type="hidden" name="material_id" id="add_quantity_material_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">أداة التعبئة</label>
                        <div class="form-control-plaintext" id="add_quantity_material_name">-</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية الحالية</label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary" id="add_quantity_existing">0</span>
                            <span id="add_quantity_unit" class="text-muted small"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية المضافة <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   class="form-control"
                                   name="additional_quantity"
                                   id="add_quantity_input"
                                   required
                                   placeholder="0.00">
                            <span class="input-group-text" id="add_quantity_unit_suffix"></span>
                        </div>
                        <small class="text-muted">سيتم جمع الكمية المدخلة مع الموجود حالياً في المخزون.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">ملاحظات (اختياري)</label>
                        <textarea class="form-control"
                                  name="notes"
                                  rows="3"
                                  maxlength="500"
                                  placeholder="مثال: إضافة من شحنة جديدة أو تصحيح جرد."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-2"></i>حفظ الكمية
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تسجيل تالف -->
<div class="modal fade" id="recordDamageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="recordDamageForm">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-octagon me-2"></i>تسجيل تالف لأداة التعبئة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="record_packaging_damage">
                    <input type="hidden" name="material_id" id="damage_material_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">أداة التعبئة</label>
                        <div class="form-control-plaintext" id="damage_material_name">-</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية الحالية</label>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-secondary" id="damage_existing">0</span>
                            <span id="damage_unit" class="text-muted small"></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">الكمية التالفة <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number"
                                   step="0.01"
                                   min="0.01"
                                   class="form-control"
                                   name="damaged_quantity"
                                   id="damage_quantity_input"
                                   required
                                   placeholder="0.00">
                            <span class="input-group-text" id="damage_unit_suffix"></span>
                        </div>
                        <small class="text-muted">سيتم خصم الكمية التالفة من المخزون الحالي.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">سبب التلف <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="damage_reason_input" rows="3" required placeholder="اذكر سبب التلف"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">تسجيل التالف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل أداة التعبئة -->
<div class="modal fade" id="editMaterialModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editMaterialForm">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل بيانات أداة التعبئة</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_packaging_material">
                    <input type="hidden" name="material_id" id="edit_material_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">اسم الأداة <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="edit_material_name" required maxlength="255">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">الفئة / النوع</label>
                            <input type="text" class="form-control" name="type" id="edit_material_type" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" id="edit_material_unit" maxlength="50">
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label">الكود الداخلي</label>
                            <input type="text" class="form-control" name="material_code" id="edit_material_code" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">الحالة</label>
                            <select class="form-select" name="status" id="edit_material_status">
                                <option value="active">نشطة</option>
                                <option value="inactive">غير نشطة</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3 mt-3">
                        <label class="form-label">المواصفات / الوصف</label>
                        <textarea class="form-control" name="specifications" id="edit_material_specifications" rows="3" maxlength="500"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">الكمية الحالية (للقراءة فقط)</label>
                        <div class="form-control-plaintext fw-semibold" id="edit_material_quantity_display">-</div>
                        <small class="text-muted">لتعديل الكمية يرجى استخدام أزرار "إضافة كمية" أو "تسجيل تالف".</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-warning text-white">
                        <i class="bi bi-check-circle me-2"></i>تحديث الأداة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal لعرض التفاصيل -->
<div class="modal fade" id="materialDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">تفاصيل استخدام الأداة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="materialDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">جاري التحميل...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
const aliasApiUrl = '<?php echo getRelativeUrl('api/update_packaging_alias.php'); ?>';
const nextCodeApiUrl = '<?php echo getRelativeUrl('production.php?page=packaging_warehouse&ajax=next_code'); ?>';

document.addEventListener('DOMContentLoaded', function () {
    const headerPrintButton = document.getElementById('printPackagingWarehouseReportButton');
    if (headerPrintButton) {
        headerPrintButton.addEventListener('click', function () {
            window.print();
        });
    }

    const reportButton = document.getElementById('generatePackagingReportBtn');
    const reportModalElement = document.getElementById('packagingReportModal');
    const viewButton = document.getElementById('packagingReportViewBtn');
    const printButton = document.getElementById('packagingReportPrintBtn');
    const createMaterialForm = document.getElementById('createMaterialForm');
    const createMaterialModal = document.getElementById('createMaterialModal');

    if (createMaterialForm) {
        const codeDisplayInput = document.getElementById('create_material_code_display');
        const codeHiddenInput = document.getElementById('create_material_code');
        const refreshCodeButton = document.getElementById('refresh_material_code_btn');
        const setGeneratedCode = (code) => {
            if (typeof code !== 'string' || code.trim() === '') {
                return;
            }
            const normalized = code.trim();
            if (codeDisplayInput) {
                codeDisplayInput.value = normalized;
                codeDisplayInput.defaultValue = normalized;
            }
            if (codeHiddenInput) {
                codeHiddenInput.value = normalized;
                codeHiddenInput.defaultValue = normalized;
            }
        };

        const fetchNextCode = async () => {
            if (!nextCodeApiUrl) {
                return;
            }
            try {
                const response = await fetch(nextCodeApiUrl, {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const payload = await response.json();
                if (payload?.success && payload.code) {
                    setGeneratedCode(payload.code);
                }
            } catch (error) {
                console.error('Failed to fetch next packaging code:', error);
            }
        };

        if (refreshCodeButton) {
            refreshCodeButton.addEventListener('click', (event) => {
                event.preventDefault();
                fetchNextCode();
            });
        }

        if (createMaterialModal) {
            createMaterialModal.addEventListener('show.bs.modal', () => {
                fetchNextCode();
            });
            createMaterialModal.addEventListener('hidden.bs.modal', () => {
                createMaterialForm.reset();
                fetchNextCode();
            });
        }
    }

    const openInNewTab = (url) => {
        if (!url) {
            alert('تعذّر فتح التقرير الآن. يرجى تحديث الصفحة وإعادة المحاولة.');
            return;
        }
        if (typeof window.openInAppModal === 'function') {
            const opener = document.activeElement instanceof Element ? document.activeElement : reportButton;
            window.openInAppModal(url, { opener: opener });
            return;
        }
        window.open(url, '_blank', 'noopener');
    };

    const hideModal = () => {
        if (!reportModalElement || typeof bootstrap === 'undefined') {
            return;
        }
        const instance = bootstrap.Modal.getInstance(reportModalElement);
        if (instance) {
            instance.hide();
        }
    };

    if (reportButton) {
        const viewUrl = reportButton.getAttribute('data-viewer-url') || '';
        const printUrlAttr = reportButton.getAttribute('data-print-url') || '';
        const isReady = reportButton.getAttribute('data-report-ready') === '1';
        const resolvedPrintUrl = printUrlAttr || (viewUrl ? (viewUrl.includes('?') ? `${viewUrl}&print=1` : `${viewUrl}?print=1`) : '');

        if (reportModalElement && reportModalElement.parentElement !== document.body) {
            document.body.appendChild(reportModalElement);
        }

        if (!isReady) {
            reportButton.addEventListener('click', () => {
                alert('لا يمكن توليد التقرير حالياً. يرجى تحديث الصفحة أو التأكد من صلاحيات مجلد التخزين.');
            });
        } else {
            reportButton.addEventListener('click', () => {
                if (!reportModalElement || typeof bootstrap === 'undefined') {
                    openInNewTab(viewUrl);
                    return;
                }
                const instance = bootstrap.Modal.getOrCreateInstance(reportModalElement);
                instance.show();
            });

            if (viewButton) {
                viewButton.addEventListener('click', () => {
                    openInNewTab(viewUrl);
                    hideModal();
                });
            }

            if (printButton) {
                printButton.addEventListener('click', () => {
                    openInNewTab(resolvedPrintUrl);
                    hideModal();
                });
            }
        }
    }
});

async function usePackagingMaterial(trigger) {
    if (!trigger) {
        return;
    }

    const materialId = trigger.dataset.id;
    if (!materialId) {
        console.warn('Material id is missing for use action.');
        return;
    }

    const materialName = trigger.dataset.name || 'أداة التعبئة';
    const unitLabel = (trigger.dataset.unit || 'وحدة').trim() || 'وحدة';
    const useQuantity = parseFloat(trigger.dataset.useQuantity || '1') || 1;
    const isIntegerQuantity = Math.abs(useQuantity - Math.round(useQuantity)) < 1e-9;
    const quantityDisplay = useQuantity.toLocaleString('ar-EG', {
        minimumFractionDigits: isIntegerQuantity ? 0 : 2,
        maximumFractionDigits: isIntegerQuantity ? 0 : 2
    });
    const confirmationMessage = `سيتم خصم ${quantityDisplay} ${unitLabel} من "${materialName}". هل تريد المتابعة؟`;

    if (!window.confirm(confirmationMessage)) {
        return;
    }

    const originalHtml = trigger.innerHTML;
    const originalDisabledState = trigger.disabled;
    trigger.disabled = true;
    trigger.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

    let shouldReload = false;

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'use_packaging_material',
                material_id: materialId
            })
        });

        const contentType = response.headers.get('Content-Type') || '';
        let payload = null;
        if (contentType.includes('application/json')) {
            payload = await response.json();
        } else {
            const text = await response.text();
            throw new Error(text.substring(0, 200) || 'استجابة غير صالحة من الخادم.');
        }

        if (response.ok && payload?.success) {
            shouldReload = !!payload.reload;
            if (shouldReload) {
                window.location.reload();
                return;
            }

            const targetId = trigger.dataset.quantityTarget;
            if (targetId) {
                const quantityElement = document.getElementById(targetId);
                if (quantityElement) {
                    const currentRaw = quantityElement.getAttribute('data-value') || quantityElement.dataset.value || '0';
                    const currentValue = parseFloat(currentRaw) || 0;
                    const updatedValue = Math.max(currentValue - useQuantity, 0);
                    const unitForElement = quantityElement.getAttribute('data-unit-label') || quantityElement.dataset.unitLabel || '';
                    const appendUnit = quantityElement.getAttribute('data-append-unit') === '1';

                    const formattedValue = updatedValue.toLocaleString('ar-EG', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });

                    quantityElement.setAttribute('data-value', updatedValue.toFixed(4));
                    quantityElement.dataset.value = updatedValue.toFixed(4);

                    if (appendUnit && unitForElement) {
                        quantityElement.textContent = `${formattedValue} ${unitForElement}`;
                    } else {
                        quantityElement.textContent = formattedValue;
                    }
                }
            }

            if (payload.message) {
                alert(payload.message);
            }
        } else {
            const errorMessage = payload?.message || 'تعذّر إتمام عملية الاستخدام.';
            alert(errorMessage);
        }
    } catch (error) {
        console.error('Use packaging material error:', error);
        alert('حدث خطأ أثناء تنفيذ العملية: ' + (error?.message || 'خطأ غير معروف'));
    } finally {
        if (!shouldReload) {
            trigger.disabled = originalDisabledState;
            trigger.innerHTML = originalHtml;
        }
    }
}

function openAddQuantityModal(trigger) {
    const modalElement = document.getElementById('addQuantityModal');
    const form = document.getElementById('addQuantityForm');
    if (!modalElement || !form) {
        return;
    }

    const materialIdInput = document.getElementById('add_quantity_material_id');
    const nameElement = document.getElementById('add_quantity_material_name');
    const existingElement = document.getElementById('add_quantity_existing');
    const unitElement = document.getElementById('add_quantity_unit');
    const unitSuffix = document.getElementById('add_quantity_unit_suffix');
    const quantityInput = document.getElementById('add_quantity_input');

    if (form) {
        form.reset();
    }

    const dataset = trigger?.dataset || {};
    const materialId = dataset.id || '';
    const materialName = dataset.name || '-';
    const unit = dataset.unit || 'وحدة';
    const existingQuantity = parseFloat(dataset.quantity || '0') || 0;

    if (!materialId) {
        console.warn('Material id is missing for add quantity modal trigger.');
        return;
    }

    materialIdInput.value = materialId;
    nameElement.textContent = materialName;
    existingElement.textContent = existingQuantity.toLocaleString('ar-EG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    unitElement.textContent = unit;
    unitSuffix.textContent = unit;
    quantityInput.value = '';

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    setTimeout(() => {
        quantityInput.focus();
        quantityInput.select();
    }, 250);
}

function openRecordDamageModal(trigger) {
    const modalElement = document.getElementById('recordDamageModal');
    const form = document.getElementById('recordDamageForm');
    if (!modalElement || !form) {
        return;
    }

    form.reset();

    const dataset = trigger?.dataset || {};
    const materialId = dataset.id || '';
    const materialName = dataset.name || '-';
    const unit = dataset.unit || 'وحدة';
    const existingQuantity = parseFloat(dataset.quantity || '0') || 0;

    if (!materialId) {
        console.warn('Material id is missing for damage modal trigger.');
        return;
    }

    document.getElementById('damage_material_id').value = materialId;
    document.getElementById('damage_material_name').textContent = materialName;
    document.getElementById('damage_existing').textContent = existingQuantity.toLocaleString('ar-EG', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    document.getElementById('damage_unit').textContent = unit;
    document.getElementById('damage_unit_suffix').textContent = unit;
    document.getElementById('damage_quantity_input').value = '';
    document.getElementById('damage_reason_input').value = '';

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    setTimeout(() => {
        document.getElementById('damage_quantity_input').focus();
        document.getElementById('damage_quantity_input').select();
    }, 250);
}

function viewMaterialDetails(materialId) {
    const modal = new bootstrap.Modal(document.getElementById('materialDetailsModal'));
    const content = document.getElementById('materialDetailsContent');
    
    content.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">جاري التحميل...</span></div></div>';
    modal.show();
    
    // الحصول على المسار الصحيح للـ API
    const currentUrl = window.location.href;
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ajax', '1');
    urlParams.set('material_id', materialId);
    urlParams.delete('p'); // إزالة pagination
    
    const apiUrl = window.location.pathname + '?' + urlParams.toString();
    
    // AJAX call to get material details
    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
        },
        credentials: 'same-origin'
    })
        .then(async response => {
            // التحقق من نوع المحتوى
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Non-JSON response:', text);
                console.error('Response status:', response.status);
                console.error('Response URL:', response.url);
                throw new Error('استجابة غير صحيحة من الخادم: ' + text.substring(0, 200));
            }
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('HTTP error response:', errorText);
                throw new Error('HTTP error! status: ' + response.status + ' - ' + errorText.substring(0, 200));
            }
            
            return response.json();
        })
        .then(data => {
            if (data.success) {
                let html = '<div class="mb-3"><h6><i class="bi bi-box-seam me-2"></i>استخدامات الأداة في عمليات الإنتاج</h6></div>';
                if (data.productions && data.productions.length > 0) {
                    html += '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th>رقم العملية</th><th>المنتج</th><th>الكمية المستخدمة</th><th>التاريخ</th></tr></thead><tbody>';
                    data.productions.forEach(prod => {
                        const date = prod.date ? new Date(prod.date).toLocaleDateString('ar-EG') : '-';
                        html += `<tr><td>#${prod.production_id}</td><td>${prod.product_name || '-'}</td><td>${prod.quantity_used || 0}</td><td>${date}</td></tr>`;
                    });
                    html += '</tbody></table></div>';
                } else {
                    html += '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد استخدامات لهذه الأداة في عمليات الإنتاج</div>';
                }
                content.innerHTML = html;
            } else {
                content.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + (data.message || 'حدث خطأ') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error loading material details:', error);
            console.error('Error details:', {
                message: error.message,
                name: error.name,
                stack: error.stack
            });
            content.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>حدث خطأ في تحميل البيانات. يرجى المحاولة مرة أخرى.<br><small>' + (error.message || '') + '</small></div>';
        });
}

function editMaterial(materialId) {
    const currentUrl = window.location.href;
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('ajax', '1');
    urlParams.set('material_id', materialId);
    urlParams.delete('p');
    
    const apiUrl = window.location.pathname + '?' + urlParams.toString();

    fetch(apiUrl, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
        },
        credentials: 'same-origin'
    })
        .then(async response => {
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error('HTTP error! status: ' + response.status + ' - ' + errorText.substring(0, 200));
            }
            return response.json();
        })
        .then(data => {
            if (!data.success || !data.material) {
                throw new Error(data.message || 'تعذر تحميل بيانات الأداة');
            }
            openEditModalFromData(data.material);
        })
        .catch(error => {
            console.error('Error loading material data for edit:', error);
            alert('حدث خطأ أثناء تحميل بيانات الأداة للتعديل. يرجى المحاولة لاحقاً.\n' + (error.message || ''));
        });
}

function openEditModalFromData(material) {
    const modalElement = document.getElementById('editMaterialModal');
    const form = document.getElementById('editMaterialForm');
    if (!modalElement || !form) {
        console.warn('Edit modal not available.');
        return;
    }

    form.reset();

    const materialIdInput = document.getElementById('edit_material_id');
    const nameInput = document.getElementById('edit_material_name');
    const typeInput = document.getElementById('edit_material_type');
    const unitInput = document.getElementById('edit_material_unit');
    const codeInput = document.getElementById('edit_material_code');
    const specsInput = document.getElementById('edit_material_specifications');
    const statusSelect = document.getElementById('edit_material_status');
    const quantityDisplay = document.getElementById('edit_material_quantity_display');

    materialIdInput.value = material.id ?? '';
    nameInput.value = material.name ?? '';

    const resolvedType = (material.type ?? '') || (material.category ?? '');
    typeInput.value = resolvedType;

    unitInput.value = material.unit ?? '';
    codeInput.value = material.material_id ?? '';
    specsInput.value = material.specifications ?? '';

    if (statusSelect) {
        const availableStatuses = Array.from(statusSelect.options).map(option => option.value);
        const desiredStatus = material.status ?? 'active';
        statusSelect.value = availableStatuses.includes(desiredStatus) ? desiredStatus : 'active';
    }

    if (quantityDisplay) {
        const numericQuantity = parseFloat(material.quantity ?? 0);
        if (Number.isFinite(numericQuantity)) {
            quantityDisplay.textContent = numericQuantity.toLocaleString('ar-EG', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        } else {
            quantityDisplay.textContent = '-';
        }
    }

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    setTimeout(() => {
        nameInput.focus();
        nameInput.select();
    }, 200);
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const aliasForms = document.querySelectorAll('.alias-inline-form');

    aliasForms.forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            const materialId = form.getAttribute('data-material-id');
            if (!materialId) {
                return;
            }

            const input = form.querySelector('input[name="alias"]');
            const submitButton = form.querySelector('button[type="submit"]');
            const statusEl = form.querySelector('.alias-status');
            const aliasValue = input ? input.value.trim() : '';

            if (submitButton) {
                submitButton.disabled = true;
                if (!submitButton.dataset.originalLabel) {
                    submitButton.dataset.originalLabel = submitButton.innerHTML;
                }
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
            }

            if (statusEl) {
                statusEl.textContent = 'جارٍ الحفظ...';
                statusEl.classList.remove('text-success', 'text-danger');
                statusEl.classList.add('text-muted');
            }

            try {
                const response = await fetch(aliasApiUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        material_id: materialId,
                        alias: aliasValue
                    })
                });

                let data = null;
                const contentType = response.headers.get('Content-Type') || '';

                if (contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const rawText = await response.text();
                    throw new Error('Response is not JSON: ' + rawText.substring(0, 200));
                }

                if (data.success) {
                    if (statusEl) {
                        statusEl.textContent = data.message || 'تم الحفظ بنجاح.';
                        statusEl.classList.remove('text-muted', 'text-danger');
                        statusEl.classList.add('text-success');
                    }

                    let aliasDisplay = form.previousElementSibling;
                    if (!(aliasDisplay && aliasDisplay.classList && aliasDisplay.classList.contains('alias-display'))) {
                        const container = form.closest('td, .card-body');
                        aliasDisplay = container ? container.querySelector('.alias-display') : null;
                    }

                    if (aliasDisplay) {
                        const aliasTextEl = aliasDisplay.querySelector('.alias-text');
                        const emptyText = aliasDisplay.getAttribute('data-empty-text') || 'لا يوجد اسم مستعار';

                        if (aliasTextEl) {
                            aliasTextEl.textContent = aliasValue !== '' ? aliasValue : emptyText;
                        }

                        aliasDisplay.classList.remove('text-info', 'text-muted');
                        aliasDisplay.classList.add(aliasValue !== '' ? 'text-info' : 'text-muted');
                    }
                } else {
                    if (statusEl) {
                        statusEl.textContent = data.message || 'تعذّر حفظ الاسم المستعار.';
                        statusEl.classList.remove('text-muted', 'text-success');
                        statusEl.classList.add('text-danger');
                    }
                }
            } catch (error) {
                console.error('Alias update error:', error);
                if (statusEl) {
                    statusEl.textContent = 'تعذّر حفظ الاسم المستعار: ' + (error?.message || 'خطأ غير معروف');
                    statusEl.classList.remove('text-muted', 'text-success');
                    statusEl.classList.add('text-danger');
                }
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitButton.dataset.originalLabel || 'حفظ';
                }
            }
        });
    });
});
</script>


<style>
/* تحسين عرض الجدول لجعله أكثر إحكاماً */
.table-sm {
    font-size: 0.875rem !important;
}

.table-sm th,
.table-sm td {
    padding: 0.4rem 0.25rem !important;
    vertical-align: middle !important;
}

.table-sm thead th {
    font-weight: 600;
    font-size: 0.8rem;
    white-space: nowrap;
    background-color: #f8f9fa;
}

.table-sm tbody td {
    font-size: 0.85rem;
}

.table-sm .btn-group .btn {
    padding: 0.2rem 0.4rem !important;
    font-size: 0.75rem !important;
}

.table-sm .badge {
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
}

/* تحسين عرض النصوص الطويلة */
.table-sm td {
    word-wrap: break-word;
    max-width: 200px;
}

.table-sm td:first-child {
    max-width: 40px;
}

.table-sm td:nth-child(2) {
    max-width: 250px;
}

/* تحسين line-height للصفوف */
.table-sm tbody tr {
    height: auto;
    min-height: 45px;
}

/* تعطيل تأثير hover في جدول أدوات التعبئة */
.packaging-table tbody tr {
    transition: none !important;
}

.packaging-table tbody tr:hover {
    background-color: inherit !important;
    transform: none !important;
}

.packaging-table.table-striped tbody tr:nth-of-type(odd):hover {
    background-color: var(--bg-secondary, #f8f9fa) !important;
}

/* تحسين المسافة بين العناصر داخل الخلايا */
.table-sm td div {
    margin: 0;
    line-height: 1.3;
}

/* تحسين عرض التواريخ */
.table-sm td:nth-child(7),
.table-sm td:nth-child(8) {
    font-size: 0.8rem;
    white-space: nowrap;
}

/* تحسين عرض الأزرار */
.table-sm .btn-group {
    display: flex;
    gap: 2px;
}

@media (max-width: 991px) {
    .table-sm {
        font-size: 0.8rem !important;
    }
    
    .table-sm th,
    .table-sm td {
        padding: 0.3rem 0.2rem !important;
    }
}
</style>

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

