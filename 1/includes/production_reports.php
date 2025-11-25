<?php
/**
 * تقارير الإنتاج الشهرية التفصيلية (استهلاك + توريدات)
 */

if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/consumption_reports.php';
require_once __DIR__ . '/production_helper.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/simple_telegram.php';
require_once __DIR__ . '/path_helper.php';

/**
 * الحصول على مفتاح الشهر بصيغة YYYY-MM
 */
function productionReportMonthKey(int $month, int $year): string
{
    $month = max(1, min(12, $month));
    $year = $year > 0 ? $year : (int) date('Y');
    return sprintf('%04d-%02d', $year, $month);
}

/**
 * اسم الشهر باللغة العربية.
 */
function productionReportArabicMonth(int $month): string
{
    $months = [
        1 => 'يناير',
        2 => 'فبراير',
        3 => 'مارس',
        4 => 'أبريل',
        5 => 'مايو',
        6 => 'يونيو',
        7 => 'يوليو',
        8 => 'أغسطس',
        9 => 'سبتمبر',
        10 => 'أكتوبر',
        11 => 'نوفمبر',
        12 => 'ديسمبر',
    ];
    return $months[$month] ?? (string) $month;
}

/**
 * تحديد نطاق التقرير الشهري (بداية ونهاية الشهر مع إمكانية التقييد بتاريخ محدد).
 *
 * @return array{month:int,year:int,month_key:string,start:string,end:string}
 */
function resolveProductionReportRange(int $month, int $year, ?string $upToDate = null): array
{
    $month = max(1, min(12, $month));
    $year = $year > 0 ? $year : (int) date('Y');

    $start = sprintf('%04d-%02d-01', $year, $month);
    $startTs = strtotime($start);
    $end = date('Y-m-t', $startTs);

    if ($upToDate !== null && $upToDate !== '') {
        $overrideTs = strtotime((string) $upToDate);
        if ($overrideTs !== false) {
            $override = date('Y-m-d', $overrideTs);
            if ($override < $start) {
                $end = $start;
            } elseif ($override > $end) {
                $end = $end;
            } else {
                $end = $override;
            }
        }
    }

    return [
        'month' => $month,
        'year' => $year,
        'month_key' => productionReportMonthKey($month, $year),
        'start' => $start,
        'end' => $end,
    ];
}

/**
 * ضمان وجود جدول سجلات تقارير الإنتاج الشهرية.
 */
function ensureProductionMonthlyReportLogTable(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    try {
        $db = db();
        $db->execute(
            "CREATE TABLE IF NOT EXISTS `production_monthly_report_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `month_key` varchar(7) NOT NULL,
                `month_number` tinyint(2) NOT NULL,
                `year_number` smallint(4) NOT NULL,
                `sent_via` varchar(40) NOT NULL DEFAULT 'telegram_auto',
                `triggered_by` int(11) DEFAULT NULL,
                `report_snapshot` longtext DEFAULT NULL,
                `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `production_monthly_report_logs_unique` (`month_key`,`sent_via`),
                KEY `production_monthly_report_logs_month_idx` (`month_number`,`year_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensureProductionMonthlyReportLogTable failed: ' . $e->getMessage());
    }
}

/**
 * التحقق مما إذا تم إرسال تقرير الشهر عبر قناة معينة.
 */
function hasProductionMonthlyReportBeenSent(string $monthKey, string $via = 'telegram_auto'): bool
{
    ensureProductionMonthlyReportLogTable();

    try {
        $db = db();
        $row = $db->queryOne(
            "SELECT id FROM production_monthly_report_logs WHERE month_key = ? AND sent_via = ? LIMIT 1",
            [$monthKey, $via]
        );
        return !empty($row);
    } catch (Throwable $e) {
        error_log('hasProductionMonthlyReportBeenSent error: ' . $e->getMessage());
        return false;
    }
}

/**
 * تسجيل إرسال تقرير الإنتاج الشهري.
 *
 * @param array<string,mixed>|null $snapshot
 */
function markProductionMonthlyReportSent(
    string $monthKey,
    string $via,
    ?array $snapshot = null,
    ?int $triggeredBy = null
): void {
    ensureProductionMonthlyReportLogTable();

    [$year, $month] = array_map('intval', explode('-', $monthKey . '-'));

    try {
        $db = db();
        $db->execute(
            "INSERT INTO production_monthly_report_logs
                (month_key, month_number, year_number, sent_via, triggered_by, report_snapshot, sent_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE sent_at = VALUES(sent_at), triggered_by = VALUES(triggered_by), report_snapshot = VALUES(report_snapshot)",
            [
                $monthKey,
                $month,
                $year,
                $via,
                $triggeredBy,
                $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    } catch (Throwable $e) {
        error_log('markProductionMonthlyReportSent error: ' . $e->getMessage());
    }
}

/**
 * تصنيف سجل التوريد إلى فئة مناسبة.
 *
 * @return array{group:string,category_key:string,category_label:string}
 */
function classifyProductionSupplyCategory(?string $category): array
{
    $value = (string) $category;
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));

    $packagingCategories = [
        'packaging',
        'packaging_material',
        'packaging_materials',
        'packaging_tool',
        'packaging_tools',
        'tool',
        'tools',
    ];

    if (in_array($normalized, $packagingCategories, true)) {
        return [
            'group' => 'packaging',
            'category_key' => 'packaging',
            'category_label' => 'أدوات التعبئة',
        ];
    }

    $rawMap = [
        'honey' => 'العسل',
        'olive_oil' => 'زيت الزيتون',
        'beeswax' => 'شمع العسل',
        'derivatives' => 'المشتقات',
        'nuts' => 'المكسرات',
        'raw' => 'مواد خام',
        'raw_material' => 'مواد خام',
        'raw_materials' => 'مواد خام',
    ];

    if (isset($rawMap[$normalized])) {
        return [
            'group' => 'raw',
            'category_key' => $normalized,
            'category_label' => $rawMap[$normalized],
        ];
    }

    return [
        'group' => 'raw',
        'category_key' => $normalized !== '' ? $normalized : 'other',
        'category_label' => 'مواد خام أخرى',
    ];
}

/**
 * بناء ملخص سجلات التوريد لأدوات التعبئة والمواد الخام.
 *
 * @param array<int,array<string,mixed>> $logs
 * @return array{
 *   total_entries:int,
 *   overall_quantity:float,
 *   groups:array<string,array{
 *     label:string,
 *     total_quantity:float,
 *     entries:array<int,array<string,mixed>>,
 *     category_totals:array<int,array<string,mixed>>
 *   }>
 * }
 */
function buildProductionSupplySummary(array $logs): array
{
    $summary = [
        'total_entries' => 0,
        'overall_quantity' => 0.0,
        'groups' => [
            'packaging' => [
                'label' => 'أدوات التعبئة',
                'total_quantity' => 0.0,
                'entries' => [],
                'category_totals' => [],
            ],
            'raw' => [
                'label' => 'المواد الخام',
                'total_quantity' => 0.0,
                'entries' => [],
                'category_totals' => [],
            ],
        ],
    ];

    foreach ($logs as $log) {
        $summary['total_entries']++;

        $classification = classifyProductionSupplyCategory($log['material_category'] ?? '');
        $groupKey = $classification['group'];
        $categoryKey = $classification['category_key'];
        $categoryLabel = $classification['category_label'];

        $quantity = (float) ($log['quantity'] ?? 0);
        $unit = trim((string) ($log['unit'] ?? ''));
        $unit = $unit !== '' ? $unit : 'وحدة';

        $group =& $summary['groups'][$groupKey];
        $group['total_quantity'] += $quantity;
        $summary['overall_quantity'] += $quantity;

        if (!isset($group['category_totals'][$categoryKey])) {
            $group['category_totals'][$categoryKey] = [
                'label' => $categoryLabel,
                'total' => 0.0,
                'entries' => 0,
            ];
        }
        $group['category_totals'][$categoryKey]['total'] += $quantity;
        $group['category_totals'][$categoryKey]['entries']++;

        $recordedAtRaw = $log['recorded_at'] ?? null;
        $recordedAt = $recordedAtRaw ? date('Y-m-d H:i', strtotime((string) $recordedAtRaw)) : null;

        $supplierName = trim((string) ($log['supplier_name'] ?? ''));
        if ($supplierName === '' && !empty($log['supplier_id'])) {
            $supplierName = 'مورد #' . intval($log['supplier_id']);
        }
        if ($supplierName === '') {
            $supplierName = 'غير محدد';
        }

        $details = trim((string) ($log['details'] ?? ''));

        $group['entries'][] = [
            'recorded_at' => $recordedAt,
            'material_label' => trim((string) ($log['material_label'] ?? 'غير محدد')),
            'category_label' => $categoryLabel,
            'quantity' => $quantity,
            'unit' => $unit,
            'supplier' => $supplierName,
            'details' => $details,
            'stock_source' => $log['stock_source'] ?? '',
            'stock_id' => $log['stock_id'] ?? null,
        ];
    }

    foreach ($summary['groups'] as $key => $group) {
        if (!empty($group['entries'])) {
            usort(
                $summary['groups'][$key]['entries'],
                static function ($a, $b): int {
                    return strcmp($b['recorded_at'] ?? '', $a['recorded_at'] ?? '');
                }
            );
        }

        if (!empty($group['category_totals'])) {
            uasort(
                $summary['groups'][$key]['category_totals'],
                static function ($a, $b): int {
                    return $b['total'] <=> $a['total'];
                }
            );
            $summary['groups'][$key]['category_totals'] = array_values(
                array_map(
                    static function ($row): array {
                        $row['total'] = round((float) $row['total'], 3);
                        return $row;
                    },
                    $summary['groups'][$key]['category_totals']
                )
            );
        } else {
            $summary['groups'][$key]['category_totals'] = [];
        }

        $summary['groups'][$key]['total_quantity'] = round((float) $summary['groups'][$key]['total_quantity'], 3);
    }

    $summary['overall_quantity'] = round((float) $summary['overall_quantity'], 3);

    return $summary;
}

/**
 * بناء بيانات التقرير الشهري التفصيلي (استهلاك + توريدات).
 *
 * @return array<string,mixed>
 */
function buildMonthlyProductionDetailedPayload(string $dateFrom, string $dateTo): array
{
    $fromTs = strtotime($dateFrom);
    $toTs = strtotime($dateTo);
    if ($fromTs === false || $toTs === false) {
        $today = date('Y-m-d');
        $fromTs = strtotime($today);
        $toTs = $fromTs;
    }
    if ($fromTs > $toTs) {
        [$fromTs, $toTs] = [$toTs, $fromTs];
    }

    $normalizedFrom = date('Y-m-d', $fromTs);
    $normalizedTo = date('Y-m-d', $toTs);

    $consumption = getConsumptionSummary($normalizedFrom, $normalizedTo);
    $supplyLogs = getProductionSupplyLogs($normalizedFrom, $normalizedTo);
    $supplySummary = buildProductionSupplySummary($supplyLogs);

    return [
        'date_from' => $normalizedFrom,
        'date_to' => $normalizedTo,
        'generated_at' => date('Y-m-d H:i:s'),
        'consumption' => $consumption,
        'supply' => $supplySummary,
    ];
}

/**
 * إنشاء HTML للتقرير الشهري التفصيلي.
 *
 * @param array<string,mixed> $data
 * @param array<string,string> $meta
 */
function buildMonthlyProductionDetailedReportHtml(array $data, array $meta): string
{
    $title = $meta['title'] ?? 'التقرير الشهري التفصيلي لخط الإنتاج';
    $period = $meta['period'] ?? ($data['date_from'] . ' - ' . $data['date_to']);
    $subtitle = $meta['subtitle'] ?? 'ملخص استهلاك المواد الخام وأدوات التعبئة مع سجلات التوريد';
    $generatedAt = $data['generated_at'] ?? date('Y-m-d H:i:s');

    $consumption = $data['consumption'] ?? [];
    $packagingItems = $consumption['packaging']['items'] ?? [];
    $rawItems = $consumption['raw']['items'] ?? [];

    $packagingSupply = $data['supply']['groups']['packaging'] ?? ['entries' => [], 'total_quantity' => 0, 'category_totals' => []];
    $rawSupply = $data['supply']['groups']['raw'] ?? ['entries' => [], 'total_quantity' => 0, 'category_totals' => []];

    $packagingConsumptionTotal = round((float) ($consumption['packaging']['total_out'] ?? 0), 3);
    $rawConsumptionTotal = round((float) ($consumption['raw']['total_out'] ?? 0), 3);

    $packagingSupplyTotal = round((float) ($packagingSupply['total_quantity'] ?? 0), 3);
    $rawSupplyTotal = round((float) ($rawSupply['total_quantity'] ?? 0), 3);

    $html = '<!DOCTYPE html>';
    $html .= '<html lang="ar" dir="rtl"><head><meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html .= '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    $html .= '<style>';
    $html .= 'body{font-family:"Cairo","Segoe UI",Tahoma,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:32px;}';
    $html .= '.report-container{max-width:1100px;margin:0 auto;background:#fff;border-radius:18px;box-shadow:0 25px 70px rgba(15,23,42,0.08);padding:32px;}';
    $html .= '.report-header{display:flex;flex-direction:column;gap:8px;margin-bottom:24px;}';
    $html .= '.report-header h1{margin:0;font-size:28px;font-weight:700;color:#1e293b;}';
    $html .= '.report-header p{margin:0;font-size:15px;color:#64748b;}';
    $html .= '.meta-info{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;color:#475569;font-size:14px;}';
    $html .= '.meta-pill{background:#e2e8f0;border-radius:999px;padding:6px 16px;font-weight:500;}';
    $html .= '.toolbar{display:flex;justify-content:flex-end;margin-bottom:20px;}';
    $html .= '.toolbar button{background:#1d4ed8;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:600;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease;}';
    $html .= '.toolbar button:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(29,78,216,0.18);}';
    $html .= '.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}';
    $html .= '.stat-card{background:linear-gradient(135deg,#eff6ff,#e0f2fe);border-radius:16px;padding:20px;position:relative;overflow:hidden;}';
    $html .= '.stat-card::after{content:"";position:absolute;inset:0;opacity:0.08;background:radial-gradient(circle at top left,#1d4ed8,transparent 60%);}';
    $html .= '.stat-card h3{margin:0 0 8px;font-size:16px;color:#0f172a;}';
    $html .= '.stat-card .value{font-size:26px;font-weight:700;color:#1d4ed8;}';
    $html .= '.section{margin-bottom:32px;}';
    $html .= '.section h2{margin:0 0 12px;font-size:22px;font-weight:700;color:#0f172a;}';
    $html .= '.section p{margin:0 0 16px;font-size:14px;color:#64748b;}';
    $html .= '.table-wrapper{background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 12px 35px rgba(15,23,42,0.06);}';
    $html .= 'table{width:100%;border-collapse:collapse;}';
    $html .= 'thead{background:#0f172a;color:#fff;}';
    $html .= 'thead th{padding:14px;font-size:14px;font-weight:600;text-align:right;}';
    $html .= 'tbody td{padding:12px 14px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#334155;vertical-align:middle;}';
    $html .= 'tbody tr:nth-child(even){background:#f8fafc;}';
    $html .= '.empty-state{padding:28px;margin:0;background:#f1f5f9;border-radius:14px;text-align:center;color:#94a3b8;font-weight:500;}';
    $html .= '.badge{display:inline-flex;align-items:center;gap:6px;background:#e0f2fe;color:#0369a1;border-radius:999px;padding:6px 14px;font-size:13px;}';
    $html .= '.chips{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;}';
    $html .= '.chip{background:#e2e8f0;border-radius:999px;padding:6px 14px;font-size:13px;color:#1e293b;}';
    $html .= '.chip strong{color:#0f172a;}';
    $html .= '@media print{body{padding:0;background:#fff;} .toolbar{display:none;} .report-container{box-shadow:none;margin:0;border-radius:0;}}';
    $html .= '</style></head><body>';

    $html .= '<div class="report-container">';
    $html .= '<div class="toolbar"><button id="printReportButton">طباعة / حفظ PDF</button></div>';
    $html .= '<div class="report-header">';
    $html .= '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    $html .= '<p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    $html .= '<div class="meta-info">';
    $html .= '<span class="meta-pill">الفترة: ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<span class="meta-pill">تاريخ التوليد: ' . htmlspecialchars($generatedAt, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '</div></div>';

    $html .= '<div class="stats-grid">';
    $html .= '<div class="stat-card"><h3>استهلاك أدوات التعبئة</h3><div class="value">' . number_format($packagingConsumptionTotal, 3) . '</div></div>';
    $html .= '<div class="stat-card"><h3>استهلاك المواد الخام</h3><div class="value">' . number_format($rawConsumptionTotal, 3) . '</div></div>';
    $html .= '<div class="stat-card"><h3>توريدات أدوات التعبئة</h3><div class="value">' . number_format($packagingSupplyTotal, 3) . '</div></div>';
    $html .= '<div class="stat-card"><h3>توريدات المواد الخام</h3><div class="value">' . number_format($rawSupplyTotal, 3) . '</div></div>';
    $html .= '</div>';

    // Packaging consumption section
    $html .= '<div class="section">';
    $html .= '<h2>استهلاك أدوات التعبئة</h2>';
    $html .= '<p>تفاصيل المواد المستخدمة خلال الفترة المحددة.</p>';
    if (empty($packagingItems)) {
        $html .= '<div class="empty-state">لا توجد بيانات استهلاك لأدوات التعبئة في هذه الفترة.</div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr>';
        $html .= '<th>#</th><th>الأداة</th><th>الفئة</th><th>الاستهلاك</th><th>الوارد</th><th>الصافي</th><th>عدد الحركات</th><th>الوحدة</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($packagingItems as $index => $item) {
            $html .= '<tr>';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['name'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="badge">' . htmlspecialchars($item['sub_category'] ?? '—', ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . number_format((float) ($item['total_out'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($item['total_in'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($item['net'] ?? 0), 3) . '</td>';
            $html .= '<td>' . intval($item['movements'] ?? 0) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['unit'] ?? 'وحدة', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';

    // Raw materials consumption section
    $html .= '<div class="section">';
    $html .= '<h2>استهلاك المواد الخام</h2>';
    $html .= '<p>إجمالي المواد الخام المستخدمة في خط الإنتاج.</p>';
    if (empty($rawItems)) {
        $html .= '<div class="empty-state">لا توجد بيانات استهلاك للمواد الخام في هذه الفترة.</div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr>';
        $html .= '<th>#</th><th>المادة الخام</th><th>الفئة</th><th>الاستهلاك</th><th>الوارد</th><th>الصافي</th><th>عدد الحركات</th><th>الوحدة</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rawItems as $index => $item) {
            $html .= '<tr>';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['name'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="badge">' . htmlspecialchars($item['sub_category'] ?? '—', ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . number_format((float) ($item['total_out'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($item['total_in'] ?? 0), 3) . '</td>';
            $html .= '<td>' . number_format((float) ($item['net'] ?? 0), 3) . '</td>';
            $html .= '<td>' . intval($item['movements'] ?? 0) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['unit'] ?? 'وحدة', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';

    // Packaging supply section
    $html .= '<div class="section">';
    $html .= '<h2>توريدات أدوات التعبئة</h2>';
    $html .= '<p>تفاصيل التوريدات المسجلة لأدوات التعبئة خلال الفترة.</p>';
    if (!empty($packagingSupply['category_totals'])) {
        $html .= '<div class="chips">';
        foreach ($packagingSupply['category_totals'] as $chip) {
            $html .= '<span class="chip"><strong>' . htmlspecialchars($chip['label'] ?? 'فئة', ENT_QUOTES, 'UTF-8') . '</strong> &mdash; ' . number_format((float) ($chip['total'] ?? 0), 3) . '</span>';
        }
        $html .= '</div>';
    }
    if (empty($packagingSupply['entries'])) {
        $html .= '<div class="empty-state">لا توجد توريدات مسجلة لأدوات التعبئة في هذه الفترة.</div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr>';
        $html .= '<th>التاريخ</th><th>الأداة</th><th>الفئة</th><th>الكمية</th><th>الوحدة</th><th>المورد</th><th>تفاصيل إضافية</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($packagingSupply['entries'] as $entry) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($entry['recorded_at'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($entry['material_label'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="badge">' . htmlspecialchars($entry['category_label'] ?? '—', ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . number_format((float) ($entry['quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($entry['unit'] ?? 'وحدة', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($entry['supplier'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') . '</td>';
            $detailsText = trim((string) ($entry['details'] ?? ''));
            $html .= '<td>' . ($detailsText !== '' ? htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';

    // Raw supply section
    $html .= '<div class="section">';
    $html .= '<h2>توريدات المواد الخام</h2>';
    $html .= '<p>السجلات التفصيلية لتوريد المواد الخام من الموردين.</p>';
    if (!empty($rawSupply['category_totals'])) {
        $html .= '<div class="chips">';
        foreach ($rawSupply['category_totals'] as $chip) {
            $html .= '<span class="chip"><strong>' . htmlspecialchars($chip['label'] ?? 'فئة', ENT_QUOTES, 'UTF-8') . '</strong> &mdash; ' . number_format((float) ($chip['total'] ?? 0), 3) . '</span>';
        }
        $html .= '</div>';
    }
    if (empty($rawSupply['entries'])) {
        $html .= '<div class="empty-state">لا توجد توريدات مسجلة للمواد الخام في هذه الفترة.</div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr>';
        $html .= '<th>التاريخ</th><th>المادة الخام</th><th>الفئة</th><th>الكمية</th><th>الوحدة</th><th>المورد</th><th>تفاصيل إضافية</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rawSupply['entries'] as $entry) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($entry['recorded_at'] ?? '-', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($entry['material_label'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><span class="badge">' . htmlspecialchars($entry['category_label'] ?? '—', ENT_QUOTES, 'UTF-8') . '</span></td>';
            $html .= '<td>' . number_format((float) ($entry['quantity'] ?? 0), 3) . '</td>';
            $html .= '<td>' . htmlspecialchars($entry['unit'] ?? 'وحدة', ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td>' . htmlspecialchars($entry['supplier'] ?? 'غير محدد', ENT_QUOTES, 'UTF-8') . '</td>';
            $detailsText = trim((string) ($entry['details'] ?? ''));
            $html .= '<td>' . ($detailsText !== '' ? htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';

    $html .= '</div>'; // report-container
    $html .= '<script>(function(){function triggerPrint(){window.print();}document.addEventListener("DOMContentLoaded",function(){var btn=document.getElementById("printReportButton");if(btn){btn.addEventListener("click",function(e){e.preventDefault();triggerPrint();});}var params=new URLSearchParams(window.location.search);if(params.get("print")==="1"){setTimeout(triggerPrint,600);}});})();</script>';
    $html .= '</body></html>';

    return $html;
}

/**
 * إنشاء ملف HTML للتقرير التفصيلي وإرجاع معلوماته.
 *
 * @param array<string,mixed> $payload
 * @param array<string,string> $meta
 * @return array<string,mixed>
 */
function generateMonthlyProductionDetailedReportFile(array $payload, array $meta): array
{
    $document = buildMonthlyProductionDetailedReportHtml($payload, $meta);

    $baseDir = rtrim(
        defined('REPORTS_PRIVATE_PATH')
            ? REPORTS_PRIVATE_PATH
            : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__) . '/reports/')),
        '/\\'
    );

    ensurePrivateDirectory($baseDir);

    $reportsDir = $baseDir . DIRECTORY_SEPARATOR . 'production';
    ensurePrivateDirectory($reportsDir);

    if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
        throw new RuntimeException('تعذر الوصول إلى مجلد تقارير الإنتاج. يرجى التحقق من الصلاحيات.');
    }

    $prefix = sanitizeFileName($meta['file_prefix'] ?? 'production_monthly_report');
    $prefix = $prefix !== '' ? $prefix : 'production_monthly_report';

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $tokenError) {
        $token = sha1($prefix . microtime(true) . mt_rand());
        error_log('generateMonthlyProductionDetailedReportFile: random_bytes failed, fallback token used - ' . $tokenError->getMessage());
    }

    $fileName = $prefix . '-' . date('Ymd-His') . '-' . $token . '.html';
    $filePath = $reportsDir . DIRECTORY_SEPARATOR . $fileName;

    if (@file_put_contents($filePath, $document) === false) {
        throw new RuntimeException('تعذر حفظ ملف التقرير. يرجى التحقق من الصلاحيات.');
    }

    $relativePath = 'production/' . $fileName;
    $viewerPath = 'reports/view.php?' . http_build_query(
        [
            'type' => 'production',
            'file' => $relativePath,
            'token' => $token,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $reportUrl = getRelativeUrl($viewerPath);
    $absoluteReportUrl = getAbsoluteUrl($viewerPath);
    $printUrl = $reportUrl . (str_contains($reportUrl, '?') ? '&' : '?') . 'print=1';
    $absolutePrintUrl = $absoluteReportUrl . (str_contains($absoluteReportUrl, '?') ? '&' : '?') . 'print=1';

    $consumption = $payload['consumption'] ?? [];
    $supply = $payload['supply']['groups'] ?? [];

    return [
        'file_path' => $filePath,
        'relative_path' => $relativePath,
        'viewer_path' => $viewerPath,
        'report_url' => $reportUrl,
        'absolute_report_url' => $absoluteReportUrl,
        'print_url' => $printUrl,
        'absolute_print_url' => $absolutePrintUrl,
        'token' => $token,
        'title' => $meta['title'] ?? 'التقرير الشهري التفصيلي لخط الإنتاج',
        'generated_at' => $payload['generated_at'] ?? date('Y-m-d H:i:s'),
        'total_rows' => intval(
            (is_countable($consumption['packaging']['items'] ?? null) ? count($consumption['packaging']['items']) : 0) +
            (is_countable($consumption['raw']['items'] ?? null) ? count($consumption['raw']['items']) : 0) +
            (is_countable($supply['packaging']['entries'] ?? null) ? count($supply['packaging']['entries']) : 0) +
            (is_countable($supply['raw']['entries'] ?? null) ? count($supply['raw']['entries']) : 0)
        ),
        'summary' => [
            'packaging' => round((float) ($consumption['packaging']['total_out'] ?? 0), 3),
            'raw' => round((float) ($consumption['raw']['total_out'] ?? 0), 3),
            'supply_packaging' => round((float) ($supply['packaging']['total_quantity'] ?? 0), 3),
            'supply_raw' => round((float) ($supply['raw']['total_quantity'] ?? 0), 3),
        ],
    ];
}

/**
 * إرسال التقرير الشهري التفصيلي إلى Telegram.
 *
 * @param array<string,mixed> $options
 * @return array{success:bool,message:string}
 */
function sendMonthlyProductionDetailedReportToTelegram(int $month, int $year, array $options = []): array
{
    if (!isTelegramConfigured()) {
        return [
            'success' => false,
            'message' => 'Telegram Bot غير مهيأ',
        ];
    }

    $force = !empty($options['force']);
    $triggeredBy = isset($options['triggered_by']) ? (int) $options['triggered_by'] : null;
    $dateToOverride = $options['date_to'] ?? null;

    $parts = resolveProductionReportRange($month, $year, $dateToOverride);
    $monthKey = $parts['month_key'];

    if (!$force) {
        $today = date('Y-m-d');
        $todayTs = strtotime($today);
        $startTs = strtotime($parts['start']);
        $endTs = strtotime($parts['end']);
        $lastDay = date('Y-m-t', $startTs);
        $allowedWindowStart = date('Y-m-d', strtotime('-2 days', strtotime($lastDay)));

        if ($todayTs === false || $startTs === false || $endTs === false) {
            return [
                'success' => false,
                'message' => 'تعذر تحديد نطاق التاريخ للتقرير.',
            ];
        }

        if ($today < $allowedWindowStart || $today > $lastDay) {
            return [
                'success' => false,
                'message' => 'الإرسال التلقائي متاح خلال آخر أيام الشهر فقط.',
            ];
        }

        if (hasProductionMonthlyReportBeenSent($monthKey, 'telegram_auto')) {
            return [
                'success' => false,
                'message' => 'تم إرسال التقرير الشهري بالفعل عبر الإرسال التلقائي.',
            ];
        }
    }

    $payload = buildMonthlyProductionDetailedPayload($parts['start'], $parts['end']);
    $consumption = $payload['consumption'] ?? [];
    $supply = $payload['supply']['groups'] ?? [];

    $hasConsumptionData = !empty($consumption['packaging']['items']) || !empty($consumption['raw']['items']);
    $hasSupplyData = !empty($supply['packaging']['entries']) || !empty($supply['raw']['entries']);

    if (!$hasConsumptionData && !$hasSupplyData) {
        return [
            'success' => false,
            'message' => 'لا توجد بيانات استهلاك أو توريد متاحة لهذا الشهر.',
        ];
    }

    $meta = [
        'title' => 'التقرير الشهري التفصيلي لخط الإنتاج',
        'period' => $payload['date_from'] . ' - ' . $payload['date_to'],
        'subtitle' => 'استهلاك المواد الخام وأدوات التعبئة وسجلات التوريد',
        'file_prefix' => 'production_monthly_report',
    ];

    try {
        $reportInfo = generateMonthlyProductionDetailedReportFile($payload, $meta);
    } catch (Throwable $e) {
        error_log('sendMonthlyProductionDetailedReportToTelegram: report generation failed - ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'تعذر إنشاء ملف التقرير: ' . $e->getMessage(),
        ];
    }

    $scopeLabel = 'شهر ' . productionReportArabicMonth($parts['month']) . ' ' . $parts['year'];
    $sendResult = sendReportAndDelete($reportInfo, $meta['title'], $scopeLabel);

    if (!empty($sendResult['success'])) {
        $snapshot = [
            'date_from' => $payload['date_from'],
            'date_to' => $payload['date_to'],
            'consumption_packaging' => round((float) ($consumption['packaging']['total_out'] ?? 0), 3),
            'consumption_raw' => round((float) ($consumption['raw']['total_out'] ?? 0), 3),
            'supply_packaging' => round((float) ($supply['packaging']['total_quantity'] ?? 0), 3),
            'supply_raw' => round((float) ($supply['raw']['total_quantity'] ?? 0), 3),
        ];
        markProductionMonthlyReportSent(
            $monthKey,
            $force ? 'telegram_manual' : 'telegram_auto',
            $snapshot,
            $triggeredBy
        );
    }

    return [
        'success' => !empty($sendResult['success']),
        'message' => $sendResult['message'] ?? ($sendResult['success'] ? 'تم إرسال التقرير إلى Telegram.' : 'تعذر إرسال التقرير إلى Telegram.'),
    ];
}

/**
 * إرسال التقرير الشهري تلقائياً (يتم استدعاؤه خلال آخر أيام الشهر).
 */
function maybeSendMonthlyProductionDetailedReport(int $month, int $year): void
{
    $parts = resolveProductionReportRange($month, $year);
    $monthKey = $parts['month_key'];

    if (hasProductionMonthlyReportBeenSent($monthKey, 'telegram_auto')) {
        return;
    }

    $today = date('Y-m-d');
    $startTs = strtotime($parts['start']);
    $lastDay = date('Y-m-t', $startTs);
    $allowedWindowStart = date('Y-m-d', strtotime('-2 days', strtotime($lastDay)));

    if ($today < $allowedWindowStart || $today > $lastDay) {
        return;
    }

    $result = sendMonthlyProductionDetailedReportToTelegram(
        $parts['month'],
        $parts['year'],
        [
            'force' => false,
            'date_to' => $parts['end'],
        ]
    );

    if (!$result['success']) {
        error_log('maybeSendMonthlyProductionDetailedReport failed: ' . ($result['message'] ?? 'unknown error'));
    }
}

