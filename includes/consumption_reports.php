<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_movements.php';
require_once __DIR__ . '/simple_export.php';
require_once __DIR__ . '/reports.php';

function consumptionGetTableColumns($table)
{
    static $cache = [];
    $key = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($key === '') {
        return [];
    }
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $db = db();
    try {
        $rows = $db->query("SHOW COLUMNS FROM `{$key}`");
        $cols = [];
        foreach ($rows as $row) {
            $field = $row['Field'] ?? null;
            if ($field) {
                $cols[$field] = true;
            }
        }
        $cache[$key] = $cols;
        return $cols;
    } catch (Exception $e) {
        $cache[$key] = [];
        return [];
    }
}

function consumptionGetPackagingMap()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $db = db();
    $map = [];
    try {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
        if (!empty($tableCheck)) {
            $rows = $db->query("SELECT id, material_id FROM packaging_materials");
            foreach ($rows as $row) {
                $id = intval($row['id'] ?? 0);
                $materialId = intval($row['material_id'] ?? 0);
                if ($id > 0) {
                    $map[$id] = true;
                }
                if ($materialId > 0) {
                    $map[$materialId] = true;
                }
            }
        }
    } catch (Exception $e) {
        $map = [];
    }
    return $map;
}

function consumptionClassifyProduct($product, $packagingMap)
{
    $id = intval($product['product_id'] ?? 0);
    $name = mb_strtolower($product['name'] ?? '');
    $category = mb_strtolower($product['category'] ?? '');
    $type = mb_strtolower($product['type'] ?? '');
    $spec = mb_strtolower($product['specifications'] ?? '');
    $materialType = mb_strtolower($product['material_type'] ?? '');
    if ($id > 0 && isset($packagingMap[$id])) {
        return ['packaging', 'general', 'أدوات التعبئة'];
    }
    $packKeywords = [
        'تغليف', 'pack', 'عبوة', 'زجاجة', 'غطاء', 'label', 'ملصق', 'كرتون', 'قارورة', 'علبة'
    ];
    foreach ($packKeywords as $keyword) {
        if (mb_strpos($name, $keyword) !== false || mb_strpos($category, $keyword) !== false || mb_strpos($type, $keyword) !== false || mb_strpos($spec, $keyword) !== false) {
            return ['packaging', 'general', 'أدوات التعبئة'];
        }
    }
    $rawCategories = [
        'honey' => [
            'label' => 'منتجات العسل',
            'keywords' => ['عسل', 'honey']
        ],
        'olive_oil' => [
            'label' => 'زيت الزيتون',
            'keywords' => ['زيت', 'olive']
        ],
        'beeswax' => [
            'label' => 'شمع العسل',
            'keywords' => ['شمع', 'wax']
        ],
        'derivatives' => [
            'label' => 'المشتقات',
            'keywords' => ['مشتق', 'derivative', 'extract', 'essence']
        ],
        'nuts' => [
            'label' => 'المكسرات',
            'keywords' => ['مكسر', 'nut', 'لوز', 'بندق', 'كاجو', 'فستق', 'عين جمل', 'سوداني', 'pistachio', 'cashew', 'almond', 'hazelnut', 'walnut', 'peanut']
        ]
    ];
    foreach ($rawCategories as $slug => $data) {
        foreach ($data['keywords'] as $keyword) {
            if (mb_strpos($name, $keyword) !== false || mb_strpos($category, $keyword) !== false || mb_strpos($type, $keyword) !== false || mb_strpos($spec, $keyword) !== false || mb_strpos($materialType, $keyword) !== false) {
                return ['raw', $slug, $data['label']];
            }
        }
    }
    if ($materialType !== '') {
        $materialMatches = [
            'honey_raw' => ['honey', 'منتجات العسل'],
            'honey_filtered' => ['honey', 'منتجات العسل'],
            'olive_oil' => ['olive_oil', 'زيت الزيتون'],
            'beeswax' => ['beeswax', 'شمع العسل'],
            'derivatives' => ['derivatives', 'المشتقات'],
            'nuts' => ['nuts', 'المكسرات']
        ];
        if (isset($materialMatches[$materialType])) {
            return ['raw', $materialMatches[$materialType][0], $materialMatches[$materialType][1]];
        }
    }
    return null;
}

function consumptionFormatNumber($value)
{
    return round((float)$value, 3);
}

function getConsumptionSummary($dateFrom, $dateTo)
{
    $db = db();
    $from = $dateFrom ?: date('Y-m-d');
    $to = $dateTo ?: $from;
    if (strtotime($from) > strtotime($to)) {
        $temp = $from;
        $from = $to;
        $to = $temp;
    }
    $productsColumns = consumptionGetTableColumns('products');
    $select = [
        'im.product_id',
        'p.name',
        "SUM(CASE WHEN im.type = 'out' THEN im.quantity ELSE 0 END) AS total_out",
        "SUM(CASE WHEN im.type = 'in' THEN im.quantity ELSE 0 END) AS total_in",
        'COUNT(*) AS movement_count',
        'MIN(im.created_at) AS first_movement',
        'MAX(im.created_at) AS last_movement'
    ];
    $optionalCols = ['category', 'type', 'specifications', 'unit', 'material_type'];
    foreach ($optionalCols as $col) {
        if (isset($productsColumns[$col])) {
            $select[] = "p.{$col}";
        }
    }
    $sql = "SELECT " . implode(', ', $select) . " FROM inventory_movements im INNER JOIN products p ON im.product_id = p.id WHERE DATE(im.created_at) BETWEEN ? AND ? GROUP BY im.product_id";
    $rows = [];
    try {
        $rows = $db->query($sql, [$from, $to]);
    } catch (Exception $e) {
        $rows = [];
    }
    $packagingMap = consumptionGetPackagingMap();
    $summary = [
        'date_from' => $from,
        'date_to' => $to,
        'generated_at' => date('Y-m-d H:i:s'),
        'packaging' => [
            'items' => [],
            'total_out' => 0,
            'total_in' => 0,
            'net' => 0,
            'sub_totals' => []
        ],
        'raw' => [
            'items' => [],
            'total_out' => 0,
            'total_in' => 0,
            'net' => 0,
            'sub_totals' => []
        ],
        'packaging_damage' => [
            'total' => 0,
            'items' => [],
            'logs' => []
        ],
        'raw_damage' => [
            'total' => 0,
            'categories' => [],
            'logs' => []
        ]
    ];
    $rawDamageMeta = [
        'honey' => ['label' => 'العسل', 'unit' => 'كجم'],
        'olive_oil' => ['label' => 'زيت الزيتون', 'unit' => 'لتر'],
        'beeswax' => ['label' => 'شمع العسل', 'unit' => 'كجم'],
        'derivatives' => ['label' => 'المشتقات', 'unit' => 'كجم'],
        'nuts' => ['label' => 'المكسرات', 'unit' => 'كجم']
    ];
    foreach ($rawDamageMeta as $categoryKey => $meta) {
        $summary['raw_damage']['categories'][$categoryKey] = [
            'label' => $meta['label'],
            'unit' => $meta['unit'],
            'total' => 0,
            'items' => []
        ];
    }

    foreach ($rows as $row) {
        $totalOut = (float)($row['total_out'] ?? 0);
        $totalIn = (float)($row['total_in'] ?? 0);
        if ($totalOut == 0 && $totalIn == 0) {
            continue;
        }
        $classification = consumptionClassifyProduct($row, $packagingMap);
        if (!$classification) {
            continue;
        }
        [$category, $subKey, $subLabel] = $classification;
        $item = [
            'name' => $row['name'] ?? ('#' . $row['product_id']),
            'sub_category' => $subLabel,
            'total_out' => consumptionFormatNumber($totalOut),
            'total_in' => consumptionFormatNumber($totalIn),
            'net' => consumptionFormatNumber($totalOut - $totalIn),
            'movements' => intval($row['movement_count'] ?? 0),
            'unit' => $row['unit'] ?? ''
        ];
        if ($category === 'packaging') {
            $summary['packaging']['items'][] = $item;
            $summary['packaging']['total_out'] += $item['total_out'];
            $summary['packaging']['total_in'] += $item['total_in'];
        } elseif ($category === 'raw') {
            $summary['raw']['items'][] = $item;
            $summary['raw']['total_out'] += $item['total_out'];
            $summary['raw']['total_in'] += $item['total_in'];
            if (!isset($summary['raw']['sub_totals'][$subKey])) {
                $summary['raw']['sub_totals'][$subKey] = [
                    'label' => $subLabel,
                    'total_out' => 0,
                    'total_in' => 0,
                    'net' => 0
                ];
            }
            $summary['raw']['sub_totals'][$subKey]['total_out'] += $item['total_out'];
            $summary['raw']['sub_totals'][$subKey]['total_in'] += $item['total_in'];
        }
    }
    usort($summary['packaging']['items'], function ($a, $b) {
        return $b['total_out'] <=> $a['total_out'];
    });
    usort($summary['raw']['items'], function ($a, $b) {
        return $b['total_out'] <=> $a['total_out'];
    });
    $summary['packaging']['net'] = consumptionFormatNumber($summary['packaging']['total_out'] - $summary['packaging']['total_in']);
    $summary['raw']['net'] = consumptionFormatNumber($summary['raw']['total_out'] - $summary['raw']['total_in']);
    foreach ($summary['raw']['sub_totals'] as $key => $row) {
        $summary['raw']['sub_totals'][$key]['net'] = consumptionFormatNumber($row['total_out'] - $row['total_in']);
    }

    // معالجة التلفيات في أدوات التعبئة
    try {
        $damageTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_damage_logs'");
        if (!empty($damageTableCheck)) {
            $damageLogs = $db->query(
                "SELECT l.*, 
                        COALESCE(l.material_name, pm.name, pr.name, CONCAT('أداة #', l.material_id)) AS material_label,
                        u.full_name AS recorded_by_name
                 FROM packaging_damage_logs l
                 LEFT JOIN packaging_materials pm ON l.source_table = 'packaging_materials' AND pm.id = l.material_id
                 LEFT JOIN products pr ON l.source_table = 'products' AND pr.id = l.material_id
                 LEFT JOIN users u ON l.recorded_by = u.id
                 WHERE DATE(l.created_at) BETWEEN ? AND ?
                 ORDER BY l.created_at DESC",
                [$from, $to]
            );

            $aggregated = [];
            foreach ($damageLogs as $log) {
                $key = $log['source_table'] . ':' . intval($log['material_id'] ?? 0);
                if (!isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'material_id' => intval($log['material_id'] ?? 0),
                        'source_table' => $log['source_table'] ?? 'packaging_materials',
                        'name' => $log['material_label'] ?? ('أداة #' . ($log['material_id'] ?? '')),
                        'unit' => $log['unit'] ?? 'وحدة',
                        'total_damaged' => 0,
                        'entries' => 0,
                        'last_reason' => null,
                        'last_recorded_at' => null,
                        'last_recorded_by' => null
                    ];
                }
                $aggregated[$key]['total_damaged'] += (float)($log['damaged_quantity'] ?? 0);
                $aggregated[$key]['entries'] += 1;

                $currentLast = $aggregated[$key]['last_recorded_at'];
                if ($currentLast === null || strtotime($log['created_at']) > strtotime($currentLast)) {
                    $aggregated[$key]['last_recorded_at'] = $log['created_at'];
                    $aggregated[$key]['last_reason'] = $log['reason'] ?? null;
                    $aggregated[$key]['last_recorded_by'] = $log['recorded_by_name'] ?? null;
                }
            }

            foreach ($aggregated as $item) {
                $item['total_damaged'] = consumptionFormatNumber($item['total_damaged']);
                $summary['packaging_damage']['items'][] = $item;
                $summary['packaging_damage']['total'] += $item['total_damaged'];
            }

            usort($summary['packaging_damage']['items'], function($a, $b) {
                return $b['total_damaged'] <=> $a['total_damaged'];
            });

            $summary['packaging_damage']['logs'] = $damageLogs;
        }
    } catch (Exception $e) {
        error_log('Consumption summary damage aggregation error: ' . $e->getMessage());
    }

    // معالجة التلفيات في الخامات
    try {
        $rawDamageTableCheck = $db->queryOne("SHOW TABLES LIKE 'raw_material_damage_logs'");
        if (!empty($rawDamageTableCheck)) {
            $rawDamageLogs = $db->query(
                "SELECT d.*,
                        s.name AS supplier_name,
                        u.full_name AS recorded_by_name
                 FROM raw_material_damage_logs d
                 LEFT JOIN suppliers s ON d.supplier_id = s.id
                 LEFT JOIN users u ON d.created_by = u.id
                 WHERE DATE(d.created_at) BETWEEN ? AND ?
                 ORDER BY d.created_at DESC",
                [$from, $to]
            );

            $categoryAggregated = [];

            foreach ($rawDamageLogs as $log) {
                $category = $log['material_category'] ?? '';
                if (!isset($summary['raw_damage']['categories'][$category])) {
                    continue;
                }

                $itemLabel = trim($log['item_label'] ?? 'خام');
                $variety = trim($log['variety'] ?? '');
                $supplierName = $log['supplier_name'] ?? 'غير محدد';
                $unit = $log['unit'] ?? $summary['raw_damage']['categories'][$category]['unit'];
                $itemName = $itemLabel;
                if ($variety !== '') {
                    $itemName .= ' - ' . $variety;
                }

                $key = md5($category . '|' . $supplierName . '|' . $itemName);
                if (!isset($categoryAggregated[$category])) {
                    $categoryAggregated[$category] = [];
                }
                if (!isset($categoryAggregated[$category][$key])) {
                    $categoryAggregated[$category][$key] = [
                        'name' => $itemName,
                        'supplier' => $supplierName,
                        'variety' => $variety,
                        'unit' => $unit,
                        'total_damaged' => 0.0,
                        'entries' => 0,
                        'last_reason' => null,
                        'last_recorded_at' => null,
                        'last_recorded_by' => null
                    ];
                }

                $damageQty = (float)($log['quantity'] ?? 0);
                $categoryAggregated[$category][$key]['total_damaged'] += $damageQty;
                $categoryAggregated[$category][$key]['entries'] += 1;

                $currentLast = $categoryAggregated[$category][$key]['last_recorded_at'];
                if ($currentLast === null || strtotime($log['created_at']) > strtotime($currentLast)) {
                    $categoryAggregated[$category][$key]['last_recorded_at'] = $log['created_at'];
                    $categoryAggregated[$category][$key]['last_reason'] = $log['reason'] ?? null;
                    $categoryAggregated[$category][$key]['last_recorded_by'] = $log['recorded_by_name'] ?? null;
                }

                $summary['raw_damage']['categories'][$category]['total'] += $damageQty;
                $summary['raw_damage']['total'] += $damageQty;
            }

            foreach ($categoryAggregated as $category => $items) {
                foreach ($items as $item) {
                    $formatted = $item;
                    $formatted['total_damaged_raw'] = $item['total_damaged'];
                    $formatted['total_damaged'] = number_format($item['total_damaged'], 3);
                    $summary['raw_damage']['categories'][$category]['items'][] = $formatted;
                }

                usort($summary['raw_damage']['categories'][$category]['items'], function ($a, $b) {
                    return ($b['total_damaged_raw'] ?? 0) <=> ($a['total_damaged_raw'] ?? 0);
                });
            }

            $summary['raw_damage']['logs'] = $rawDamageLogs;
        }
    } catch (Exception $e) {
        error_log('Consumption summary raw damage aggregation error: ' . $e->getMessage());
    }

    return $summary;
}

function buildConsumptionReportHtml($summary, $meta)
{
    $title = $meta['title'] ?? 'تقرير الاستهلاك';
    $periodLabel = $meta['period'] ?? '';
    $scopeLabel = $meta['scope'] ?? '';
    $primary = '#1e3a5f';
    $secondary = '#2c5282';
    $accent = '#3498db';
    $gradient = 'linear-gradient(135deg, #1e3a5f 0%, #3498db 100%)';
    $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title><style>
    body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f8fd;color:#1f2937;padding:40px;}
    .header{background:' . $gradient . ';color:#fff;padding:30px;border-radius:18px;box-shadow:0 12px 32px rgba(30,58,95,0.28);margin-bottom:35px;text-align:center;}
    .header h1{font-size:28px;margin:0;}
    .header p{margin:8px 0 0;font-size:15px;opacity:0.9;}
    .chips{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:18px;}
    .chip{background:rgba(255,255,255,0.18);padding:8px 18px;border-radius:999px;font-size:13px;backdrop-filter:blur(6px);}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin-bottom:28px;}
    .card{background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 28px rgba(15,23,42,0.08);}
    .card h3{font-size:16px;margin:0;color:' . $primary . ';}
    .card .fig{font-size:24px;font-weight:700;margin-top:12px;color:' . $secondary . ';}
    .section{margin-top:40px;}
    .section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
    .section-title h2{font-size:22px;color:' . $primary . ';margin:0;}
    .table-wrapper{background:#fff;border-radius:16px;box-shadow:0 10px 28px rgba(15,23,42,0.07);overflow:hidden;}
    table{width:100%;border-collapse:collapse;}
    th{background:' . $primary . ';color:#fff;padding:14px;font-size:13px;text-align:right;}
    td{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:13px;}
    tr:nth-child(even){background:#f8fafc;}
    .tag{display:inline-flex;align-items:center;padding:4px 12px;border-radius:999px;font-size:12px;background:rgba(52,152,219,0.15);color:' . $secondary . ';}
    .empty{padding:40px;text-align:center;color:#94a3b8;}
    .subtotals{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;}
    .subtotal{background:#fff;border-radius:14px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,0.06);min-width:180px;}
    .footer{margin-top:40px;text-align:center;font-size:12px;color:#94a3b8;}
    </style></head><body>';
    $html .= '<div class="header"><h1>' . htmlspecialchars($title) . '</h1>';
    if ($periodLabel !== '') {
        $html .= '<p>' . htmlspecialchars($periodLabel) . '</p>';
    }
    if ($scopeLabel !== '') {
        $html .= '<p>' . htmlspecialchars($scopeLabel) . '</p>';
    }
    $html .= '<div class="chips"><span class="chip">تاريخ الإنشاء: ' . htmlspecialchars($summary['generated_at']) . '</span><span class="chip">الفترة: ' . htmlspecialchars($summary['date_from']) . ' إلى ' . htmlspecialchars($summary['date_to']) . '</span></div></div>';
    $html .= '<div class="section"><div class="section-title"><h2>ملخص الأدوات</h2></div><div class="cards">';
    $html .= '<div class="card"><h3>استهلاك أدوات التعبئة</h3><div class="fig">' . number_format($summary['packaging']['total_out'], 3) . '</div></div>';
    $html .= '<div class="card"><h3>استهلاك المواد الخام</h3><div class="fig">' . number_format($summary['raw']['total_out'], 3) . '</div></div>';
    $html .= '<div class="card"><h3>الصافي الكلي</h3><div class="fig">' . number_format($summary['packaging']['net'] + $summary['raw']['net'], 3) . '</div></div>';
    $html .= '</div></div>';
    $html .= '<div class="section"><div class="section-title"><h2>أدوات التعبئة</h2></div>';
    if (empty($summary['packaging']['items'])) {
        $html .= '<div class="table-wrapper"><div class="empty">لا توجد بيانات</div></div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr><th>المادة</th><th>إجمالي الاستخدام</th><th>الوارد</th><th>الصافي</th><th>عدد الحركات</th></tr></thead><tbody>';
        foreach ($summary['packaging']['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['name']) . '</td>';
            $html .= '<td>' . number_format($item['total_out'], 3) . '</td>';
            $html .= '<td>' . number_format($item['total_in'], 3) . '</td>';
            $html .= '<td>' . number_format($item['net'], 3) . '</td>';
            $html .= '<td>' . intval($item['movements']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';
    $html .= '<div class="section"><div class="section-title"><h2>المواد الخام</h2></div>';
    if (!empty($summary['raw']['sub_totals'])) {
        $html .= '<div class="subtotals">';
        foreach ($summary['raw']['sub_totals'] as $row) {
            $html .= '<div class="subtotal"><div class="tag">' . htmlspecialchars($row['label']) . '</div><div style="margin-top:10px;font-weight:600;color:' . $secondary . '">الاستهلاك: ' . number_format($row['total_out'], 3) . '</div><div style="margin-top:6px;">الصافي: ' . number_format($row['net'], 3) . '</div></div>';
        }
        $html .= '</div>';
    }
    if (empty($summary['raw']['items'])) {
        $html .= '<div class="table-wrapper"><div class="empty">لا توجد بيانات</div></div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr><th>المادة</th><th>الفئة</th><th>إجمالي الاستخدام</th><th>الوارد</th><th>الصافي</th><th>عدد الحركات</th></tr></thead><tbody>';
        foreach ($summary['raw']['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['name']) . '</td>';
            $html .= '<td><span class="tag">' . htmlspecialchars($item['sub_category']) . '</span></td>';
            $html .= '<td>' . number_format($item['total_out'], 3) . '</td>';
            $html .= '<td>' . number_format($item['total_in'], 3) . '</td>';
            $html .= '<td>' . number_format($item['net'], 3) . '</td>';
            $html .= '<td>' . intval($item['movements']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';
    $html .= '<div class="footer">شركة البركة &mdash; نظام التقارير الذكي</div>';
    $html .= '</body></html>';
    return $html;
}

function generateConsumptionPdf($summary, $meta)
{
    $html = buildConsumptionReportHtml($summary, $meta);
    $fileName = sanitizeFileName(($meta['file_prefix'] ?? 'consumption_report') . '_' . $summary['date_from'] . '_' . $summary['date_to']) . '.html';
    $filePath = REPORTS_PATH . $fileName;
    $dir = rtrim(REPORTS_PATH, '/\\');
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($filePath, $html);
    return $filePath;
}

function sendConsumptionReport($dateFrom, $dateTo, $scopeLabel)
{
    $summary = getConsumptionSummary($dateFrom, $dateTo);
    if (empty($summary['packaging']['items']) && empty($summary['raw']['items'])) {
        return ['success' => false, 'message' => 'لا توجد بيانات لاستهلاك الفترة المحددة'];
    }
    $title = 'تقرير استهلاك الإنتاج';
    $meta = [
        'title' => $title,
        'period' => 'الفترة: ' . $summary['date_from'] . ' - ' . $summary['date_to'],
        'scope' => $scopeLabel,
        'file_prefix' => 'consumption_report'
    ];
    $filePath = generateConsumptionPdf($summary, $meta);
    $result = sendReportAndDelete($filePath, $title, $scopeLabel);
    return $result;
}


