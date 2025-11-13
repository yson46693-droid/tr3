<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';
require_once __DIR__ . '/../../includes/production_reports.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

$db = db();
$currentUser = getCurrentUser();

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$todaySummary = getConsumptionSummary($today, $today);
$monthSummary = getConsumptionSummary($monthStart, $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'send_report';
    $redirectPeriod = $_POST['period'] ?? $_GET['period'] ?? 'current_month';
    $redirectDate = $_POST['date'] ?? $_GET['date'] ?? $today;
    $redirectParams = [
        'page' => 'reports',
        'section' => 'production_reports',
        'period' => $redirectPeriod
    ];
    if ($redirectPeriod === 'day') {
        $redirectParams['date'] = $redirectDate;
    }

    if (!verifyCSRFToken($token)) {
        $_SESSION['error_message'] = 'رمز الأمان غير صالح. يرجى إعادة المحاولة.';
        preventDuplicateSubmission(null, $redirectParams, null, 'manager');
    }

    try {
        switch ($action) {
            case 'log_packaging_damage': {
                $materialId = intval($_POST['packaging_material_id'] ?? 0);
                $damagedQuantity = isset($_POST['damaged_quantity']) ? max(0.0, round((float)$_POST['damaged_quantity'], 4)) : 0.0;
                $reason = trim($_POST['damage_reason'] ?? '');

                if ($materialId <= 0) {
                    throw new RuntimeException('يرجى اختيار أداة التعبئة.');
                }
                if ($damagedQuantity <= 0) {
                    throw new RuntimeException('يرجى إدخال كمية تالفة أكبر من الصفر.');
                }
                if ($reason === '') {
                    throw new RuntimeException('يرجى إدخال سبب التلف.');
                }

                $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
                $usePackagingTable = !empty($packagingTableCheck);

                $db->beginTransaction();
                if ($usePackagingTable) {
                    $material = $db->queryOne(
                        "SELECT id, name, quantity, unit 
                         FROM packaging_materials 
                         WHERE id = ? AND status = 'active' 
                         FOR UPDATE",
                        [$materialId]
                    );
                } else {
                    $material = $db->queryOne(
                        "SELECT id, name, quantity, unit 
                         FROM products 
                         WHERE id = ? AND status = 'active' 
                         FOR UPDATE",
                        [$materialId]
                    );
                }

                if (!$material) {
                    throw new RuntimeException('أداة التعبئة غير موجودة أو غير مفعّلة.');
                }

                $quantityBefore = (float)($material['quantity'] ?? 0);
                if ($quantityBefore < $damagedQuantity) {
                    throw new RuntimeException('الكمية التالفة تتجاوز الكمية المتاحة.');
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
                            $currentUser['id'] ?? null
                        ]
                    );

                logAudit(
                    $currentUser['id'] ?? null,
                    'record_packaging_damage',
                    $usePackagingTable ? 'packaging_materials' : 'products',
                    $materialId,
                    [
                        'quantity_before' => $quantityBefore
                    ],
                    [
                        'quantity_after' => $quantityAfter,
                        'reason' => mb_substr($reason, 0, 500, 'UTF-8')
                    ]
                );

                $db->commit();

                $_SESSION['success_message'] = sprintf(
                    'تم تسجيل %.2f %s تالف من %s.',
                    $damagedQuantity,
                    $material['unit'] ?? 'وحدة',
                    $material['name'] ?? ('أداة #' . $materialId)
                );
                preventDuplicateSubmission(null, $redirectParams, null, 'manager');
            }
            break;

            case 'log_raw_damage': {
                $stockKey = $_POST['raw_stock_key'] ?? '';
                $damageQuantity = isset($_POST['damage_quantity']) ? max(0.0, round((float)$_POST['damage_quantity'], 3)) : 0.0;
                $reason = trim($_POST['damage_reason'] ?? '');
                $honeyType = $_POST['honey_type'] ?? 'raw';

                if (!preg_match('/^([a-z_]+)\|([a-z_]+)\|(\d+)$/', $stockKey, $matches)) {
                    throw new RuntimeException('يرجى اختيار المادة الخام بشكل صحيح.');
                }

                [$fullMatch, $materialCategory, $stockSource, $stockIdString] = $matches;
                $stockId = (int)$stockIdString;

                if ($damageQuantity <= 0) {
                    throw new RuntimeException('يرجى إدخال كمية تالفة أكبر من الصفر.');
                }
                if ($reason === '') {
                    throw new RuntimeException('يرجى إدخال سبب التلف.');
                }

                $validCategories = ['honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts'];
                if (!in_array($materialCategory, $validCategories, true)) {
                    throw new RuntimeException('نوع المادة الخام غير صالح.');
                }
                if ($stockId <= 0) {
                    throw new RuntimeException('يرجى اختيار سجل المخزون.');
                }

                $currentUserId = $currentUser['id'] ?? null;
                $logDetails = [
                    'material_category' => $materialCategory,
                    'stock_id' => $stockId,
                    'supplier_id' => null,
                    'item_label' => '',
                    'variety' => null,
                    'unit' => 'كجم'
                ];

                $db->beginTransaction();

                if ($materialCategory === 'honey') {
                    $honeyType = $honeyType === 'filtered' ? 'filtered' : 'raw';
                    $stock = $db->queryOne(
                        "SELECT hs.*, s.name AS supplier_name 
                         FROM honey_stock hs 
                         LEFT JOIN suppliers s ON hs.supplier_id = s.id 
                         WHERE hs.id = ? 
                         FOR UPDATE",
                        [$stockId]
                    );
                    if (!$stock) {
                        throw new RuntimeException('سجل العسل غير موجود.');
                    }
                    $available = $honeyType === 'filtered'
                        ? (float)$stock['filtered_honey_quantity']
                        : (float)$stock['raw_honey_quantity'];
                    if ($available < $damageQuantity) {
                        throw new RuntimeException('الكمية المدخلة أكبر من الكمية المتاحة.');
                    }
                    $column = $honeyType === 'filtered' ? 'filtered_honey_quantity' : 'raw_honey_quantity';
                    $itemLabel = $honeyType === 'filtered' ? 'عسل مصفى' : 'عسل خام';

                    $db->execute(
                        "UPDATE honey_stock 
                         SET {$column} = {$column} - ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$damageQuantity, $stockId]
                    );

                    $logDetails['supplier_id'] = $stock['supplier_id'];
                    $logDetails['item_label'] = $itemLabel;
                    $logDetails['variety'] = $stock['honey_variety'] ?? null;
                } elseif ($materialCategory === 'olive_oil') {
                    $stock = $db->queryOne(
                        "SELECT os.*, s.name AS supplier_name 
                         FROM olive_oil_stock os 
                         LEFT JOIN suppliers s ON os.supplier_id = s.id 
                         WHERE os.id = ? 
                         FOR UPDATE",
                        [$stockId]
                    );
                    if (!$stock) {
                        throw new RuntimeException('سجل زيت الزيتون غير موجود.');
                    }
                    if ((float)$stock['quantity'] < $damageQuantity) {
                        throw new RuntimeException('الكمية المدخلة أكبر من الكمية المتاحة.');
                    }
                    $db->execute(
                        "UPDATE olive_oil_stock 
                         SET quantity = quantity - ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$damageQuantity, $stockId]
                    );
                    $logDetails['supplier_id'] = $stock['supplier_id'];
                    $logDetails['item_label'] = 'زيت زيتون';
                    $logDetails['unit'] = 'لتر';
                } elseif ($materialCategory === 'beeswax') {
                    $stock = $db->queryOne(
                        "SELECT ws.*, s.name AS supplier_name 
                         FROM beeswax_stock ws 
                         LEFT JOIN suppliers s ON ws.supplier_id = s.id 
                         WHERE ws.id = ? 
                         FOR UPDATE",
                        [$stockId]
                    );
                    if (!$stock) {
                        throw new RuntimeException('سجل شمع العسل غير موجود.');
                    }
                    if ((float)$stock['weight'] < $damageQuantity) {
                        throw new RuntimeException('الكمية المدخلة أكبر من الكمية المتاحة.');
                    }
                    $db->execute(
                        "UPDATE beeswax_stock 
                         SET weight = weight - ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$damageQuantity, $stockId]
                    );
                    $logDetails['supplier_id'] = $stock['supplier_id'];
                    $logDetails['item_label'] = 'شمع عسل';
                } elseif ($materialCategory === 'derivatives') {
                    $stock = $db->queryOne(
                        "SELECT ds.*, s.name AS supplier_name 
                         FROM derivatives_stock ds 
                         LEFT JOIN suppliers s ON ds.supplier_id = s.id 
                         WHERE ds.id = ? 
                         FOR UPDATE",
                        [$stockId]
                    );
                    if (!$stock) {
                        throw new RuntimeException('سجل المشتقات غير موجود.');
                    }
                    if ((float)$stock['weight'] < $damageQuantity) {
                        throw new RuntimeException('الكمية المدخلة أكبر من الكمية المتاحة.');
                    }
                    $db->execute(
                        "UPDATE derivatives_stock 
                         SET weight = weight - ?, updated_at = NOW() 
                         WHERE id = ?",
                        [$damageQuantity, $stockId]
                    );
                    $logDetails['supplier_id'] = $stock['supplier_id'];
                    $logDetails['item_label'] = 'مشتق';
                    $logDetails['variety'] = $stock['derivative_type'] ?? null;
                } elseif ($materialCategory === 'nuts') {
                    if ($stockSource === 'mixed') {
                        $stock = $db->queryOne(
                            "SELECT mn.*, s.name AS supplier_name 
                             FROM mixed_nuts mn 
                             LEFT JOIN suppliers s ON mn.supplier_id = s.id 
                             WHERE mn.id = ? 
                             FOR UPDATE",
                            [$stockId]
                        );
                        if (!$stock) {
                            throw new RuntimeException('سجل المكسرات المشكلة غير موجود.');
                        }
                        if ((float)$stock['total_quantity'] < $damageQuantity) {
                            throw new RuntimeException('الكمية المدخلة أكبر من الكمية المتاحة في الخلطة.');
                        }
                        $db->execute(
                            "UPDATE mixed_nuts 
                             SET total_quantity = total_quantity - ?, updated_at = NOW() 
                             WHERE id = ?",
                            [$damageQuantity, $stockId]
                        );
                        $logDetails['supplier_id'] = $stock['supplier_id'];
                        $logDetails['item_label'] = 'مكسرات مشكلة';
                        $logDetails['variety'] = $stock['batch_name'] ?? null;
                    } else {
                        $stock = $db->queryOne(
                            "SELECT ns.*, s.name AS supplier_name 
                             FROM nuts_stock ns 
                             LEFT JOIN suppliers s ON ns.supplier_id = s.id 
                             WHERE ns.id = ? 
                             FOR UPDATE",
                            [$stockId]
                        );
                        if (!$stock) {
                            throw new RuntimeException('سجل المكسرات غير موجود.');
                        }
                        if ((float)$stock['quantity'] < $damageQuantity) {
                            throw new RuntimeException('الكمية المدخلة أكبر من الكمية المتاحة.');
                        }
                        $db->execute(
                            "UPDATE nuts_stock 
                             SET quantity = quantity - ?, updated_at = NOW() 
                             WHERE id = ?",
                            [$damageQuantity, $stockId]
                        );
                        $logDetails['supplier_id'] = $stock['supplier_id'];
                        $logDetails['item_label'] = 'مكسرات';
                        $logDetails['variety'] = $stock['nut_type'] ?? null;
                    }
                }

                $db->execute(
                    "INSERT INTO raw_material_damage_logs 
                     (material_category, stock_id, supplier_id, item_label, variety, quantity, unit, reason, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $logDetails['material_category'],
                        $logDetails['stock_id'],
                        $logDetails['supplier_id'],
                        $logDetails['item_label'],
                        $logDetails['variety'],
                        $damageQuantity,
                        $logDetails['unit'],
                        $reason ?: null,
                        $currentUserId
                    ]
                );

                logAudit(
                    $currentUserId,
                    'record_damage',
                    'raw_material_damage_logs',
                    $stockId,
                    null,
                    [
                        'category' => $materialCategory,
                        'quantity' => $damageQuantity,
                        'unit' => $logDetails['unit'],
                        'reason' => $reason
                    ]
                );

                $db->commit();

                $_SESSION['success_message'] = 'تم تسجيل التالف للمواد الخام بنجاح.';
                preventDuplicateSubmission(null, $redirectParams, null, 'manager');
            }
            break;

            case 'send_monthly_detailed_report': {
                $reportMonth = isset($_POST['report_month']) ? max(1, min(12, (int) $_POST['report_month'])) : (int) date('n');
                $reportYear = isset($_POST['report_year']) ? max(2000, (int) $_POST['report_year']) : (int) date('Y');

                $result = sendMonthlyProductionDetailedReportToTelegram(
                    $reportMonth,
                    $reportYear,
                    [
                        'force' => true,
                        'triggered_by' => $currentUser['id'] ?? null,
                        'date_to' => date('Y-m-d'),
                    ]
                );

                if (!empty($result['success'])) {
                    $_SESSION['success_message'] = $result['message'] ?? 'تم إرسال التقرير الشهري التفصيلي إلى Telegram.';
                } else {
                    $_SESSION['error_message'] = $result['message'] ?? 'تعذر إرسال التقرير الشهري التفصيلي.';
                }

                preventDuplicateSubmission(null, $redirectParams, null, 'manager');
            }
            break;

            case 'send_report':
            default: {
                $scope = $_POST['report_scope'] ?? 'day';
                $reportDate = $_POST['report_date'] ?? $today;
                switch ($scope) {
                    case 'day':
                        $dateObj = DateTime::createFromFormat('Y-m-d', $reportDate);
                        $reportDay = $dateObj ? $dateObj->format('Y-m-d') : $today;
                        $result = sendConsumptionReport($reportDay, $reportDay, 'تقرير اليوم');
                        break;
                    case 'current_month':
                        $result = sendConsumptionReport($monthStart, $today, 'تقرير الشهر الحالي');
                        break;
                    case 'previous_month':
                        $prevStart = new DateTime('first day of last month');
                        $prevEnd = new DateTime('last day of last month');
                        $result = sendConsumptionReport($prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d'), 'تقرير الشهر السابق');
                        break;
                    default:
                        $result = ['success' => false, 'message' => 'نوع التقرير غير معروف.'];
                        break;
                }
                if (!empty($result['success'])) {
                    $_SESSION['success_message'] = $result['message'] ?? 'تم إرسال التقرير.';
                } else {
                    $_SESSION['error_message'] = $result['message'] ?? 'تعذر إنشاء التقرير.';
                }

                preventDuplicateSubmission(null, $redirectParams, null, 'manager');
            }
        }
    } catch (Throwable $handlerError) {
        try {
            $db->rollback();
        } catch (Throwable $rollbackError) {
            // ignore rollback errors
        }
        $_SESSION['error_message'] = $handlerError->getMessage();
        preventDuplicateSubmission(null, $redirectParams, null, 'manager');
    }
}

function renderConsumptionTable($items, $includeCategory = false)
{
    if (empty($items)) {
        echo '<div class="table-responsive"><div class="text-center text-muted py-4">لا توجد بيانات</div></div>';
        return;
    }
    echo '<div class="table-responsive dashboard-table-wrapper">';
    echo '<table class="table dashboard-table dashboard-table--no-hover align-middle">';
    echo '<thead class="table-light"><tr>';
    echo '<th>المادة</th>';
    if ($includeCategory) {
        echo '<th>الفئة</th>';
    }
    echo '<th>الاستهلاك</th><th>الوارد</th><th>الصافي</th><th>الحركات</th>';
    echo '</tr></thead><tbody>';
    foreach ($items as $item) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['name']) . '</td>';
        if ($includeCategory) {
            echo '<td><span class="badge bg-secondary">' . htmlspecialchars($item['sub_category']) . '</span></td>';
        }
        echo '<td>' . number_format($item['total_out'], 3) . '</td>';
        echo '<td>' . number_format($item['total_in'], 3) . '</td>';
        echo '<td>' . number_format($item['net'], 3) . '</td>';
        echo '<td>' . intval($item['movements']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function renderPackagingDamageTable($items)
{
    if (empty($items)) {
        echo '<div class="text-center text-muted py-4">لا توجد تسجيلات تالف في الفترة المحددة</div>';
        return;
    }
    echo '<div class="table-responsive dashboard-table-wrapper">';
    echo '<table class="table table-no-hover dashboard-table align-middle">';
    echo '<thead class="table-light"><tr>';
    echo '<th>أداة التعبئة</th><th>الكمية التالفة</th><th>عدد السجلات</th><th>آخر سبب</th><th>آخر تسجيل</th>';
    echo '</tr></thead><tbody>';
    foreach ($items as $item) {
        $lastRecordedAt = !empty($item['last_recorded_at']) ? date('Y-m-d H:i', strtotime($item['last_recorded_at'])) : '-';
        $lastReason = $item['last_reason'] ? htmlspecialchars($item['last_reason']) : '-';
        if (!empty($item['last_recorded_by'])) {
            $lastReason .= '<br><small class="text-muted">بواسطة: ' . htmlspecialchars($item['last_recorded_by']) . '</small>';
        }
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['name']) . '<br><small class="text-muted">' . htmlspecialchars($item['unit'] ?? 'وحدة') . '</small></td>';
        echo '<td class="text-danger fw-semibold">' . number_format($item['total_damaged'], 3) . '</td>';
        echo '<td>' . intval($item['entries']) . '</td>';
        echo '<td>' . $lastReason . '</td>';
        echo '<td><span class="badge bg-light text-dark">' . $lastRecordedAt . '</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function buildCombinedReportRows(array $summary): array
{
    $rows = [];
    $totals = [
        'in' => 0.0,
        'out' => 0.0,
        'net' => 0.0,
        'movements' => 0
    ];

    foreach ($summary['packaging']['items'] as $item) {
        $rows[] = [
            'type' => 'أدوات التعبئة',
            'name' => $item['name'] ?? 'مادة غير معروفة',
            'category' => $item['sub_category'] ?? '-',
            'incoming' => (float)($item['total_in'] ?? 0),
            'outgoing' => (float)($item['total_out'] ?? 0),
            'net' => (float)($item['net'] ?? 0),
            'movements' => (int)($item['movements'] ?? 0),
            'extra' => !empty($item['unit']) ? 'الوحدة: ' . $item['unit'] : ''
        ];
    }

    foreach ($summary['raw']['items'] as $item) {
        $rows[] = [
            'type' => 'المواد الخام',
            'name' => $item['name'] ?? 'مادة خام',
            'category' => $item['sub_category'] ?? '-',
            'incoming' => (float)($item['total_in'] ?? 0),
            'outgoing' => (float)($item['total_out'] ?? 0),
            'net' => (float)($item['net'] ?? 0),
            'movements' => (int)($item['movements'] ?? 0),
            'extra' => !empty($item['unit']) ? 'الوحدة: ' . $item['unit'] : ''
        ];
    }

    foreach ($summary['packaging_damage']['items'] as $item) {
        $lastDetails = [];
        if (!empty($item['last_reason'])) {
            $lastDetails[] = 'آخر سبب: ' . $item['last_reason'];
        }
        if (!empty($item['last_recorded_by'])) {
            $lastDetails[] = 'بواسطة: ' . $item['last_recorded_by'];
        }
        if (!empty($item['last_recorded_at'])) {
            $lastDetails[] = 'تاريخ آخر تسجيل: ' . date('Y-m-d H:i', strtotime($item['last_recorded_at']));
        }
        $totalDamaged = (float)($item['total_damaged'] ?? 0);
        $rows[] = [
            'type' => 'تالف أدوات التعبئة',
            'name' => $item['name'] ?? 'غير محدد',
            'category' => $item['unit'] ?? '-',
            'incoming' => 0.0,
            'outgoing' => $totalDamaged,
            'net' => -$totalDamaged,
            'movements' => (int)($item['entries'] ?? 0),
            'extra' => implode(' | ', array_filter($lastDetails))
        ];
    }

    if (!empty($summary['raw_damage']['categories'])) {
        foreach ($summary['raw_damage']['categories'] as $categoryData) {
            if (empty($categoryData['items'])) {
                continue;
            }
            foreach ($categoryData['items'] as $item) {
                $totalDamaged = isset($item['total_damaged_raw'])
                    ? (float)$item['total_damaged_raw']
                    : (float)str_replace(',', '', $item['total_damaged'] ?? 0);
                $details = [];
                if (!empty($item['supplier'])) {
                    $details[] = 'المورد: ' . $item['supplier'];
                }
                if (!empty($item['last_reason'])) {
                    $details[] = 'آخر سبب: ' . $item['last_reason'];
                }
                if (!empty($item['last_recorded_by'])) {
                    $details[] = 'بواسطة: ' . $item['last_recorded_by'];
                }
                if (!empty($item['last_recorded_at'])) {
                    $details[] = 'تاريخ آخر تسجيل: ' . date('Y-m-d H:i', strtotime($item['last_recorded_at']));
                }

                $rows[] = [
                    'type' => 'تالف المواد الخام',
                    'name' => $item['name'] ?? 'مادة خام',
                    'category' => $categoryData['label'] ?? '-',
                    'incoming' => 0.0,
                    'outgoing' => $totalDamaged,
                    'net' => -$totalDamaged,
                    'movements' => (int)($item['entries'] ?? 0),
                    'extra' => implode(' | ', array_filter($details))
                ];
            }
        }
    }

    $orderMap = [
        'أدوات التعبئة' => 1,
        'المواد الخام' => 2,
        'تالف أدوات التعبئة' => 3,
        'تالف المواد الخام' => 4
    ];

    usort($rows, static function ($a, $b) use ($orderMap) {
        $orderA = $orderMap[$a['type']] ?? 99;
        $orderB = $orderMap[$b['type']] ?? 99;
        if ($orderA === $orderB) {
            return ($b['outgoing'] ?? 0) <=> ($a['outgoing'] ?? 0);
        }
        return $orderA <=> $orderB;
    });

    foreach ($rows as $row) {
        $totals['in'] += $row['incoming'];
        $totals['out'] += $row['outgoing'];
        $totals['net'] += $row['net'];
        $totals['movements'] += $row['movements'];
    }

    return ['rows' => $rows, 'totals' => $totals];
}

function renderCombinedReportTable(array $rows, array $totals)
{
    if (empty($rows)) {
        echo '<div class="text-center text-muted py-4">لا توجد بيانات للفترة المحددة</div>';
        return;
    }

    echo '<div class="table-responsive dashboard-table-wrapper">';
    echo '<table class="table dashboard-table align-middle">';
    echo '<thead class="table-light"><tr>';
    echo '<th>النوع</th><th>العنصر</th><th>الفئة / التفاصيل</th><th>الوارد</th><th>الاستهلاك</th><th>الصافي</th><th>الحركات</th><th>معلومات إضافية</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $extra = $row['extra'] !== '' ? nl2br(htmlspecialchars($row['extra'], ENT_QUOTES, 'UTF-8')) : '<span class="text-muted">—</span>';
        echo '<tr>';
        echo '<td><span class="badge bg-primary-subtle text-primary">' . htmlspecialchars($row['type']) . '</span></td>';
        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['category'] ?? '-') . '</td>';
        echo '<td>' . number_format($row['incoming'], 3) . '</td>';
        echo '<td>' . number_format($row['outgoing'], 3) . '</td>';
        $netClass = $row['net'] < 0 ? 'text-danger' : 'text-success';
        echo '<td class="' . $netClass . '">' . number_format($row['net'], 3) . '</td>';
        echo '<td>' . number_format($row['movements']) . '</td>';
        echo '<td>' . $extra . '</td>';
        echo '</tr>';
    }

    echo '</tbody><tfoot class="table-light"><tr>';
    echo '<th colspan="3" class="text-end">الإجمالي</th>';
    echo '<th>' . number_format($totals['in'], 3) . '</th>';
    echo '<th>' . number_format($totals['out'], 3) . '</th>';
    $totalNetClass = $totals['net'] < 0 ? 'text-danger' : 'text-success';
    echo '<th class="' . $totalNetClass . '">' . number_format($totals['net'], 3) . '</th>';
    echo '<th>' . number_format($totals['movements']) . '</th>';
    echo '<th></th>';
    echo '</tr></tfoot></table></div>';
}

$csrfToken = generateCSRFToken();

?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4" id="productionReportsSection">
    <div>
        <h2 class="mb-1"><i class="bi bi-graph-up-arrow me-2"></i>تقارير الإنتاج</h2>
        <p class="text-muted mb-0">متابعة استهلاك أدوات التعبئة والمواد الخام</p>
    </div>
    <div class="d-flex flex-column flex-md-row align-items-md-end gap-2">
        <form method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="send_report">
            <input type="hidden" name="section" value="production_reports">
            <div class="col-sm-5 col-lg-4">
                <label class="form-label">نوع التقرير</label>
                <select class="form-select" name="report_scope" id="reportScopeSelect">
                    <option value="day">تقرير عن يوم محدد</option>
                    <option value="current_month" selected>تقرير الشهر الحالي</option>
                    <option value="previous_month">تقرير الشهر السابق</option>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3 d-none" id="reportScopeDateField">
                <label class="form-label">اختر اليوم</label>
                <input type="date" class="form-control" name="report_date" value="<?php echo htmlspecialchars($today); ?>">
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-primary w-100 btn-sm btn-md" style="min-width: 180px; padding-top: 0.5rem; padding-bottom: 0.5rem;">
                    <i class="bi bi-send-check me-1"></i>إرسال التقرير
                </button>
            </div>
        </form>
        <form method="post" class="d-flex">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="send_monthly_detailed_report">
            <input type="hidden" name="section" value="production_reports">
            <input type="hidden" name="report_month" value="<?php echo (int) date('n'); ?>">
            <input type="hidden" name="report_year" value="<?php echo (int) date('Y'); ?>">
            <button class="btn btn-outline-secondary btn-sm btn-md w-100" style="min-width: 220px; padding-top: 0.5rem; padding-bottom: 0.5rem;">
                <i class="bi bi-clipboard-pulse me-1"></i>التقرير الشهري المفصل لخط الإنتاج
            </button>
        </form>
    </div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($successMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary js-report-nav" type="button" data-target="#productionReportsBlock">
                <i class="bi bi-graph-up-arrow me-1"></i>
                تقارير الإنتاج
            </button>
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#financialReportsSection">
                <i class="bi bi-cash-coin me-1"></i>
                تقارير مالية
            </button>
        </div>
    </div>
</div>

<section id="productionReportsBlock" class="mb-5">
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#productionReportsSection">
                <i class="bi bi-gear-wide-connected me-1"></i>
                إعداد التقرير
            </button>
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#reportFiltersSection">
                <i class="bi bi-funnel me-1"></i>
                خيارات الفلترة
            </button>
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#summarySection">
                <i class="bi bi-clipboard-data me-1"></i>
                ملخص الفترة
            </button>
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#damageLoggingSection">
                <i class="bi bi-journal-plus me-1"></i>
                تسجيل التوالف
            </button>
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#complianceSection">
                <i class="bi bi-shield-check me-1"></i>
                متابعة التوالف
            </button>
            <button class="btn btn-outline-primary js-report-nav" type="button" data-target="#combinedTableSection">
                <i class="bi bi-table me-1"></i>
                الجدول الموحد
            </button>
        </div>
    </div>
</div>

<?php
function renderSummaryCards($label, $summary)
{
    echo '<div class="card mb-4 shadow-sm"><div class="card-body">';
    echo '<div class="d-flex justify-content-between align-items-center mb-3">';
    echo '<div><h4 class="mb-1">' . htmlspecialchars($label) . '</h4><span class="text-muted">' . htmlspecialchars($summary['date_from']) . ' &mdash; ' . htmlspecialchars($summary['date_to']) . '</span></div>';
    echo '<span class="badge bg-primary-subtle text-primary">آخر تحديث: ' . htmlspecialchars($summary['generated_at']) . '</span>';
    echo '</div>';
    echo '<div class="row g-3">';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">استهلاك أدوات التعبئة</div><div class="fs-4 fw-semibold text-primary">' . number_format($summary['packaging']['total_out'], 3) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">استهلاك المواد الخام</div><div class="fs-4 fw-semibold text-primary">' . number_format($summary['raw']['total_out'], 3) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">الصافي الكلي</div><div class="fs-4 fw-semibold text-success">' . number_format($summary['packaging']['net'] + $summary['raw']['net'], 3) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">إجمالي الحركات</div><div class="fs-4 fw-semibold text-secondary">' . number_format(array_sum(array_column($summary['packaging']['items'], 'movements')) + array_sum(array_column($summary['raw']['items'], 'movements'))) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100 border-danger-subtle bg-danger-subtle bg-opacity-10"><div class="text-muted small mb-1">التالف من أدوات التعبئة</div><div class="fs-4 fw-semibold text-danger">' . number_format($summary['packaging_damage']['total'], 3) . '</div></div></div>';
    $rawDamageTotal = isset($summary['raw_damage']['total']) ? $summary['raw_damage']['total'] : 0;
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100 border-warning-subtle bg-warning-subtle bg-opacity-10"><div class="text-muted small mb-1">التالف من المواد الخام</div><div class="fs-4 fw-semibold text-warning">' . number_format($rawDamageTotal, 3) . '</div></div></div>';
    echo '</div>';
    echo '</div></div>';
}

function renderDamageComplianceCard(array $compliance)
{
    if (empty($compliance)) {
        return;
    }
    echo '<div class="card mb-4 shadow-sm">';
    echo '<div class="card-header bg-warning-subtle text-warning d-flex justify-content-between align-items-center">';
    echo '<span><i class="bi bi-shield-check me-2"></i>متابعة تسجيل التوالف حسب الأقسام</span>';
    echo '<span class="small text-warning-emphasis">يجب تسجيل أي تالف بشكل يومي</span>';
    echo '</div>';
    echo '<div class="card-body"><div class="row g-3">';
    foreach ($compliance as $department) {
        $label = $department['label'] ?? 'قسم غير محدد';
        $entries = (int)($department['entries'] ?? 0);
        $totalDamaged = (float)($department['total_damaged'] ?? 0);
        $hasRecords = !empty($department['has_records']);
        $lastRecordedAt = $department['last_recorded_at'] ?? null;
        $lastRecordedBy = $department['last_recorded_by'] ?? null;

        $statusClass = $hasRecords ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
        $statusIcon = $hasRecords ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
        $statusLabel = $hasRecords ? 'مسجل' : 'غير مسجل';
        $lastRecordedDisplay = '<span class="text-muted">لا يوجد تسجيل</span>';
        if (!empty($lastRecordedAt)) {
            $formattedDate = date('Y-m-d H:i', strtotime($lastRecordedAt));
            $lastRecordedDisplay = htmlspecialchars($formattedDate);
            if (!empty($lastRecordedBy)) {
                $lastRecordedDisplay .= '<br><small class="text-muted">بواسطة: ' . htmlspecialchars($lastRecordedBy) . '</small>';
            }
        }

        echo '<div class="col-md-4 col-lg-3">';
        echo '<div class="border rounded-3 p-3 h-100 shadow-sm">';
        echo '<div class="d-flex justify-content-between align-items-center mb-2">';
        echo '<span class="fw-semibold">' . htmlspecialchars($label) . '</span>';
        echo '<span class="badge ' . $statusClass . '"><i class="bi ' . $statusIcon . ' me-1"></i>' . htmlspecialchars($statusLabel) . '</span>';
        echo '</div>';
        echo '<div class="small text-muted mb-1">عدد السجلات: <span class="fw-semibold">' . number_format($entries) . '</span></div>';
        echo '<div class="small text-muted mb-1">إجمالي التالف: <span class="fw-semibold text-danger">' . number_format($totalDamaged, 3) . '</span></div>';
        echo '<div class="small text-muted">آخر تسجيل:<br><span class="fw-semibold">' . $lastRecordedDisplay . '</span></div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div></div>';
    echo '</div>';
}

$availablePeriods = ['current_month', 'previous_month', 'day'];
$selectedPeriod = $_GET['period'] ?? 'current_month';
if (!in_array($selectedPeriod, $availablePeriods, true)) {
    $selectedPeriod = 'current_month';
}

$selectedDateInput = $_GET['date'] ?? $today;
$dateObject = DateTime::createFromFormat('Y-m-d', $selectedDateInput);
if (!$dateObject) {
    $dateObject = new DateTime($today);
}
$selectedDateValue = $dateObject->format('Y-m-d');

switch ($selectedPeriod) {
    case 'previous_month':
        $prevStart = new DateTime('first day of last month');
        $prevEnd = new DateTime('last day of last month');
        $rangeStart = $prevStart->format('Y-m-d');
        $rangeEnd = $prevEnd->format('Y-m-d');
        $rangeLabel = 'الشهر السابق';
        break;
    case 'day':
        $rangeStart = $selectedDateValue;
        $rangeEnd = $selectedDateValue;
        $rangeLabel = 'اليوم (' . $selectedDateValue . ')';
        break;
    case 'current_month':
    default:
        $rangeStart = date('Y-m-01');
        $rangeEnd = $today;
        $rangeLabel = 'الشهر الحالي';
        break;
}

$rangeDisplay = $rangeStart === $rangeEnd
    ? $rangeStart
    : $rangeStart . ' — ' . $rangeEnd;

$selectedSummary = getConsumptionSummary($rangeStart, $rangeEnd);
$combinedData = buildCombinedReportRows($selectedSummary);
$combinedRows = $combinedData['rows'];
$combinedTotals = $combinedData['totals'];
$recordsCount = count($combinedRows);

// بيانات التوالف - أدوات التعبئة
$packagingMaterialsOptions = [];
$usePackagingTableForForm = false;
try {
    $packagingTableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
    $usePackagingTableForForm = !empty($packagingTableCheck);
    if ($usePackagingTableForForm) {
        $packagingMaterialsOptions = $db->query(
            "SELECT id, name, quantity, unit 
             FROM packaging_materials 
             WHERE status = 'active' 
             ORDER BY name"
        );
    } else {
        $packagingMaterialsOptions = $db->query(
            "SELECT id, name, quantity, unit 
             FROM products 
             WHERE status = 'active' 
               AND (category LIKE '%تغليف%' OR type LIKE '%تغليف%' OR name LIKE '%تغليف%')
             ORDER BY name"
        );
    }
} catch (Exception $packagingOptionsError) {
    $packagingMaterialsOptions = [];
}

// بيانات التوالف - المواد الخام
$rawDamageOptionsData = [
    'honey' => [],
    'olive_oil' => [],
    'beeswax' => [],
    'derivatives' => [],
    'nuts' => []
];

try {
    $honeyStocks = $db->query(
        "SELECT hs.id, hs.honey_variety, hs.raw_honey_quantity, hs.filtered_honey_quantity, s.name AS supplier_name 
         FROM honey_stock hs 
         LEFT JOIN suppliers s ON hs.supplier_id = s.id
         WHERE COALESCE(hs.raw_honey_quantity, 0) > 0 OR COALESCE(hs.filtered_honey_quantity, 0) > 0
         ORDER BY s.name IS NULL, s.name ASC, hs.honey_variety ASC"
    );
    foreach ($honeyStocks as $item) {
        $rawDamageOptionsData['honey'][] = [
            'id' => (int)($item['id'] ?? 0),
            'supplier' => $item['supplier_name'] ?? null,
            'variety' => $item['honey_variety'] ?? null,
            'raw_available' => (float)($item['raw_honey_quantity'] ?? 0),
            'filtered_available' => (float)($item['filtered_honey_quantity'] ?? 0),
            'unit' => 'كجم',
            'source' => 'single'
        ];
    }

    $oliveStocks = $db->query(
        "SELECT os.id, os.quantity, s.name AS supplier_name 
         FROM olive_oil_stock os 
         LEFT JOIN suppliers s ON os.supplier_id = s.id
         WHERE COALESCE(os.quantity, 0) > 0
         ORDER BY s.name IS NULL, s.name ASC"
    );
    foreach ($oliveStocks as $item) {
        $rawDamageOptionsData['olive_oil'][] = [
            'id' => (int)($item['id'] ?? 0),
            'supplier' => $item['supplier_name'] ?? null,
            'available' => (float)($item['quantity'] ?? 0),
            'unit' => 'لتر',
            'source' => 'single'
        ];
    }

    $beeswaxStocks = $db->query(
        "SELECT ws.id, ws.weight, s.name AS supplier_name 
         FROM beeswax_stock ws 
         LEFT JOIN suppliers s ON ws.supplier_id = s.id
         WHERE COALESCE(ws.weight, 0) > 0
         ORDER BY s.name IS NULL, s.name ASC"
    );
    foreach ($beeswaxStocks as $item) {
        $rawDamageOptionsData['beeswax'][] = [
            'id' => (int)($item['id'] ?? 0),
            'supplier' => $item['supplier_name'] ?? null,
            'available' => (float)($item['weight'] ?? 0),
            'unit' => 'كجم',
            'source' => 'single'
        ];
    }

    $derivativeStocks = $db->query(
        "SELECT ds.id, ds.weight, ds.derivative_type, s.name AS supplier_name 
         FROM derivatives_stock ds 
         LEFT JOIN suppliers s ON ds.supplier_id = s.id
         WHERE COALESCE(ds.weight, 0) > 0
         ORDER BY s.name IS NULL, s.name ASC, ds.derivative_type ASC"
    );
    foreach ($derivativeStocks as $item) {
        $rawDamageOptionsData['derivatives'][] = [
            'id' => (int)($item['id'] ?? 0),
            'supplier' => $item['supplier_name'] ?? null,
            'available' => (float)($item['weight'] ?? 0),
            'variety' => $item['derivative_type'] ?? null,
            'unit' => 'كجم',
            'source' => 'single'
        ];
    }

    $nutsStocks = $db->query(
        "SELECT ns.id, ns.quantity, ns.nut_type, s.name AS supplier_name 
         FROM nuts_stock ns 
         LEFT JOIN suppliers s ON ns.supplier_id = s.id
         WHERE COALESCE(ns.quantity, 0) > 0
         ORDER BY s.name IS NULL, s.name ASC, ns.nut_type ASC"
    );
    foreach ($nutsStocks as $item) {
        $rawDamageOptionsData['nuts'][] = [
            'id' => (int)($item['id'] ?? 0),
            'supplier' => $item['supplier_name'] ?? null,
            'available' => (float)($item['quantity'] ?? 0),
            'variety' => $item['nut_type'] ?? null,
            'unit' => 'كجم',
            'source' => 'single'
        ];
    }

    $mixedNutsStocks = $db->query(
        "SELECT mn.id, mn.total_quantity, mn.batch_name, s.name AS supplier_name 
         FROM mixed_nuts mn 
         LEFT JOIN suppliers s ON mn.supplier_id = s.id
         WHERE COALESCE(mn.total_quantity, 0) > 0
         ORDER BY s.name IS NULL, s.name ASC, mn.batch_name ASC"
    );
    foreach ($mixedNutsStocks as $item) {
        $rawDamageOptionsData['nuts'][] = [
            'id' => (int)($item['id'] ?? 0),
            'supplier' => $item['supplier_name'] ?? null,
            'available' => (float)($item['total_quantity'] ?? 0),
            'variety' => $item['batch_name'] ?? null,
            'unit' => 'كجم',
            'source' => 'mixed'
        ];
    }
} catch (Exception $rawOptionsError) {
    $rawDamageOptionsData = [
        'honey' => [],
        'olive_oil' => [],
        'beeswax' => [],
        'derivatives' => [],
        'nuts' => []
    ];
}

$rawDamageOptionsJson = json_encode($rawDamageOptionsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP);
$rawDamageOptionsJson = $rawDamageOptionsJson ?: '{}';
$hasPackagingOptions = !empty($packagingMaterialsOptions);
$hasRawDamageOptions = false;
foreach ($rawDamageOptionsData as $categoryItems) {
    if (!empty($categoryItems)) {
        $hasRawDamageOptions = true;
        break;
    }
}
?>

<div class="card mb-4 shadow-sm" id="reportFiltersSection">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="section" value="production_reports">
            <div class="col-md-4">
                <label class="form-label">الفترة</label>
                <select class="form-select" id="reportPeriod" name="period">
                    <option value="current_month" <?php echo $selectedPeriod === 'current_month' ? 'selected' : ''; ?>>الشهر الحالي</option>
                    <option value="previous_month" <?php echo $selectedPeriod === 'previous_month' ? 'selected' : ''; ?>>الشهر السابق</option>
                    <option value="day" <?php echo $selectedPeriod === 'day' ? 'selected' : ''; ?>>يوم محدد</option>
                </select>
            </div>
            <div class="col-md-4 <?php echo $selectedPeriod === 'day' ? '' : 'd-none'; ?>" id="dayFilterField">
                <label class="form-label">اختر اليوم</label>
                <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($selectedDateValue); ?>">
            </div>
            <div class="col-md-4 col-lg-3">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-secondary w-100">
                    <i class="bi bi-filter-circle me-1"></i>تطبيق الفلتر
                </button>
            </div>
        </form>
        <div class="mt-3 text-muted small">
            <i class="bi bi-calendar-range me-1"></i>الفترة المختارة: <?php echo htmlspecialchars($rangeDisplay); ?>
        </div>
    </div>
</div>

<section id="summarySection" class="mb-4">
    <?php renderSummaryCards('ملخص ' . $rangeLabel, $selectedSummary); ?>
</section>

<section id="damageLoggingSection" class="mb-4">
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-danger-subtle text-danger d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-archive-dash me-2"></i>تسجيل تالف أدوات التعبئة</span>
                    <span class="badge bg-light text-danger">يؤثر على المخزون فوراً</span>
                </div>
                <div class="card-body">
                    <?php if ($hasPackagingOptions): ?>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="log_packaging_damage">
                        <input type="hidden" name="period" value="<?php echo htmlspecialchars($selectedPeriod); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDateValue); ?>">
                        <div class="col-12">
                            <label class="form-label fw-semibold">أداة التعبئة <span class="text-danger">*</span></label>
                            <select class="form-select" id="packagingDamageMaterial" name="packaging_material_id" required>
                                <option value="">اختر أداة التعبئة</option>
                                <?php foreach ($packagingMaterialsOptions as $option): ?>
                                    <?php
                                    $materialId = (int)($option['id'] ?? 0);
                                    if ($materialId <= 0) {
                                        continue;
                                    }
                                    $materialName = trim((string)($option['name'] ?? ''));
                                    if ($materialName === '') {
                                        $materialName = 'أداة #' . $materialId;
                                    }
                                    $materialQuantity = isset($option['quantity']) ? (float)$option['quantity'] : 0.0;
                                    $materialUnit = trim((string)($option['unit'] ?? 'وحدة'));
                                    ?>
                                    <option
                                        value="<?php echo $materialId; ?>"
                                        data-unit="<?php echo htmlspecialchars($materialUnit, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-available="<?php echo htmlspecialchars(number_format($materialQuantity, 4, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <?php echo htmlspecialchars($materialName, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($materialQuantity > 0): ?>
                                            — متاح <?php echo number_format($materialQuantity, 2); ?> <?php echo htmlspecialchars($materialUnit, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php else: ?>
                                            — لا يوجد رصيد متاح
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted d-block mt-2" id="packagingDamageAvailability">—</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">الكمية التالفة <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" step="0.01" min="0.01" name="damaged_quantity" id="packagingDamageQuantity" required>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="alert alert-warning bg-warning-subtle text-warning-emphasis w-100 mb-0 py-2 small">
                                سيتم خصم الكمية التالفة مباشرةً من المخزون.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">سبب التلف <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="damage_reason" rows="2" placeholder="مثال: تالف بسبب التخزين أو النقل" required></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-save me-1"></i>
                                تسجيل التالف
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            لا توجد أدوات تعبئة متاحة للتسجيل حالياً.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-warning-subtle text-warning d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-droplet-half me-2"></i>تسجيل تالف المواد الخام</span>
                    <span class="badge bg-light text-warning">يشمل العسل والزيوت والمكسرات</span>
                </div>
                <div class="card-body">
                    <?php if ($hasRawDamageOptions): ?>
                    <form method="post" class="row g-3" id="rawDamageForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="log_raw_damage">
                        <input type="hidden" name="period" value="<?php echo htmlspecialchars($selectedPeriod); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDateValue); ?>">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">نوع المادة <span class="text-danger">*</span></label>
                            <select class="form-select" name="material_category" id="rawDamageCategory" required>
                                <option value="honey">العسل</option>
                                <option value="olive_oil">زيت الزيتون</option>
                                <option value="beeswax">شمع العسل</option>
                                <option value="derivatives">المشتقات</option>
                                <option value="nuts">المكسرات</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">السجل المتأثر <span class="text-danger">*</span></label>
                            <select class="form-select" name="raw_stock_key" id="rawDamageStock" required>
                                <option value="">اختر السجل</option>
                            </select>
                            <small class="text-muted d-block mt-2" id="rawDamageAvailability">—</small>
                        </div>
                        <div class="col-md-6 d-none" id="rawDamageHoneyTypeRow">
                            <label class="form-label fw-semibold">نوع العسل</label>
                            <select class="form-select" name="honey_type" id="rawDamageHoneyType">
                                <option value="raw">عسل خام</option>
                                <option value="filtered">عسل مصفى</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">الكمية التالفة <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" step="0.01" min="0.01" name="damage_quantity" id="rawDamageQuantity" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">سبب التلف <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="damage_reason" rows="2" placeholder="مثال: انتهاء الصلاحية أو تلف أثناء التخزين" required></textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-warning text-white">
                                <i class="bi bi-save me-1"></i>
                                تسجيل التالف
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            لا توجد مخزونات خام متاحة لتسجيل التوالف حالياً.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php $damageCompliance = $selectedSummary['damage_compliance'] ?? []; ?>
<?php if (!empty($damageCompliance)): ?>
<section id="complianceSection" class="mb-4">
    <?php renderDamageComplianceCard($damageCompliance); ?>
</section>
<?php endif; ?>

<div class="card shadow-sm" id="combinedTableSection">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>الجدول الموحد للفترة: <?php echo htmlspecialchars($rangeLabel); ?></span>
        <span class="badge bg-primary-subtle text-primary">عدد السجلات: <?php echo number_format($recordsCount); ?></span>
    </div>
    <div class="card-body">
        <?php renderCombinedReportTable($combinedRows, $combinedTotals); ?>
    </div>
</div>
</section>

<section id="financialReportsSection" class="mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="mb-1"><i class="bi bi-cash-stack me-2"></i>تقارير مالية</h2>
            <p class="text-muted mb-0">نظرة عامة على المعاملات والتحصيلات المالية</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(getDashboardUrl('accountant')); ?>?page=collections">
                <i class="bi bi-wallet2 me-1"></i>
                ملف التحصيلات
            </a>
            <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(getDashboardUrl('accountant')); ?>?page=invoices">
                <i class="bi bi-receipt me-1"></i>
                إدارة الفواتير
            </a>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-3 col-sm-6">
            <div class="card shadow-sm h-100 border-success-subtle">
                <div class="card-body">
                    <div class="text-muted small mb-1">إجمالي الدخل (الشهر الحالي)</div>
                    <div class="fs-4 fw-semibold text-success">—</div>
                    <small class="text-muted d-block mt-2">يتم جلب البيانات من قسم المحاسبة</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-sm-6">
            <div class="card shadow-sm h-100 border-danger-subtle">
                <div class="card-body">
                    <div class="text-muted small mb-1">إجمالي المصروفات (الشهر الحالي)</div>
                    <div class="fs-4 fw-semibold text-danger">—</div>
                    <small class="text-muted d-block mt-2">تأكد من تسجيل جميع المصروفات في النظام</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-sm-6">
            <div class="card shadow-sm h-100 border-warning-subtle">
                <div class="card-body">
                    <div class="text-muted small mb-1">الفواتير المستحقة</div>
                    <div class="fs-4 fw-semibold text-warning">—</div>
                    <small class="text-muted d-block mt-2">راجع قائمة الفواتير لتسريع التحصيل</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-sm-6">
            <div class="card shadow-sm h-100 border-primary-subtle">
                <div class="card-body">
                    <div class="text-muted small mb-1">آخر تحديث مالي</div>
                    <div class="fs-4 fw-semibold text-primary">—</div>
                    <small class="text-muted d-block mt-2">سيتم إظهار آخر عملية مالية تمت</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <span><i class="bi bi-info-circle me-2"></i>ملاحظات</span>
        </div>
        <div class="card-body">
            <p class="mb-2">تعمل هذه الصفحة كمنصة موحدة لعرض التقارير المالية. لتمكين عرض الأرقام تلقائياً:</p>
            <ul class="mb-0 text-muted small">
                <li>تأكد من تسجيل جميع العمليات المالية في قسم المحاسبة.</li>
                <li>استخدم أقسام التحصيلات والفواتير لتحديث الأرصدة والمستحقات.</li>
                <li>يمكن تطوير تقارير مخصصة وربطها هنا حسب احتياج الإدارة.</li>
            </ul>
        </div>
    </div>
</section>

<script>
    const rawDamageOptions = <?php echo $rawDamageOptionsJson; ?>;
    document.addEventListener('DOMContentLoaded', function () {
        const periodSelect = document.getElementById('reportPeriod');
        const dayField = document.getElementById('dayFilterField');
        const scopeSelect = document.getElementById('reportScopeSelect');
        const scopeDateField = document.getElementById('reportScopeDateField');
        const params = new URLSearchParams(window.location.search);
        const targetSection = document.getElementById('productionReportsSection');
        const navButtons = document.querySelectorAll('.js-report-nav');

        const toggleScopeDateField = () => {
            if (!scopeSelect || !scopeDateField) {
                return;
            }
            if (scopeSelect.value === 'day') {
                scopeDateField.classList.remove('d-none');
            } else {
                scopeDateField.classList.add('d-none');
            }
        };

        if (scopeSelect) {
            scopeSelect.addEventListener('change', toggleScopeDateField);
            toggleScopeDateField();
        }

        navButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const targetSelector = btn.getAttribute('data-target');
                if (!targetSelector) {
                    return;
                }
                const section = document.querySelector(targetSelector);
                if (section) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        const packagingSelect = document.getElementById('packagingDamageMaterial');
        const packagingQuantityInput = document.getElementById('packagingDamageQuantity');
        const packagingInfo = document.getElementById('packagingDamageAvailability');
        if (packagingSelect && packagingInfo) {
            const updatePackagingInfo = () => {
                const option = packagingSelect.options[packagingSelect.selectedIndex];
                if (!option || !option.dataset.available) {
                    packagingInfo.textContent = '—';
                    if (packagingQuantityInput) {
                        packagingQuantityInput.removeAttribute('max');
                    }
                    return;
                }
                const available = parseFloat(option.dataset.available || '0');
                const unit = option.dataset.unit || 'وحدة';
                if (Number.isFinite(available) && available > 0) {
                    packagingInfo.textContent = `المتاح حالياً: ${available.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${unit}`;
                    if (packagingQuantityInput) {
                        packagingQuantityInput.max = available;
                    }
                } else {
                    packagingInfo.textContent = 'لا يوجد رصيد متاح حالياً في المخزون.';
                    if (packagingQuantityInput) {
                        packagingQuantityInput.removeAttribute('max');
                    }
                }
            };
            packagingSelect.addEventListener('change', updatePackagingInfo);
            updatePackagingInfo();
        }

        const rawCategorySelect = document.getElementById('rawDamageCategory');
        const rawStockSelect = document.getElementById('rawDamageStock');
        const rawAvailability = document.getElementById('rawDamageAvailability');
        const honeyTypeRow = document.getElementById('rawDamageHoneyTypeRow');
        const honeyTypeSelect = document.getElementById('rawDamageHoneyType');
        const rawQuantityInput = document.getElementById('rawDamageQuantity');

        const populateRawStocks = (category) => {
            if (!rawStockSelect) {
                return;
            }
            rawStockSelect.innerHTML = '<option value="">اختر السجل</option>';
            if (rawAvailability) {
                rawAvailability.textContent = '—';
            }
            if (honeyTypeRow) {
                honeyTypeRow.classList.toggle('d-none', category !== 'honey');
            }
            if (honeyTypeSelect) {
                honeyTypeSelect.value = 'raw';
            }
            if (honeyTypeSelect) {
                honeyTypeSelect.value = 'raw';
            }

            const options = (rawDamageOptions && rawDamageOptions[category]) ? rawDamageOptions[category] : [];
            options.forEach((item) => {
                const option = document.createElement('option');
                const source = item.source || 'single';
                option.value = `${category}|${source}|${item.id}`;

                const labelParts = [];
                if (item.supplier) {
                    labelParts.push(item.supplier);
                }
                if (item.variety) {
                    labelParts.push(item.variety);
                }
                let labelText = `#${item.id}`;
                if (labelParts.length) {
                    labelText += ' - ' + labelParts.join(' | ');
                }

                const availableValue = (item.available !== undefined)
                    ? parseFloat(item.available)
                    : parseFloat(item.raw_available ?? item.filtered_available ?? 0);
                const unit = item.unit || 'كجم';

                option.dataset.unit = unit;
                option.dataset.supplier = item.supplier || '';
                option.dataset.variety = item.variety || '';
                option.dataset.source = source;
                if (item.raw_available !== undefined) {
                    option.dataset.rawAvailable = item.raw_available;
                }
                if (item.filtered_available !== undefined) {
                    option.dataset.filteredAvailable = item.filtered_available;
                }
                if (item.available !== undefined) {
                    option.dataset.available = item.available;
                }

                const displayAvailable = Number.isFinite(availableValue) ? availableValue : 0;
                option.textContent = `${labelText} — متاح ${displayAvailable.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${unit}`;
                rawStockSelect.appendChild(option);
            });
        };

        const updateRawAvailability = () => {
            if (!rawStockSelect || !rawAvailability) {
                return;
            }
            const option = rawStockSelect.options[rawStockSelect.selectedIndex];
            if (!option || !option.value) {
                rawAvailability.textContent = '—';
                if (rawQuantityInput) {
                    rawQuantityInput.removeAttribute('max');
                }
                return;
            }
            const unit = option.dataset.unit || 'كجم';
            const [category] = option.value.split('|');

            if (category === 'honey') {
                const rawAvailable = parseFloat(option.dataset.rawAvailable || '0');
                const filteredAvailable = parseFloat(option.dataset.filteredAvailable || '0');
                const rawText = rawAvailable.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const filteredText = filteredAvailable.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                rawAvailability.innerHTML = `خام: ${rawText} ${unit} &mdash; مصفى: ${filteredText} ${unit}`;
                const selectedHoneyType = honeyTypeSelect ? honeyTypeSelect.value : 'raw';
                const availableForType = selectedHoneyType === 'filtered' ? filteredAvailable : rawAvailable;
                if (rawQuantityInput) {
                    if (Number.isFinite(availableForType) && availableForType > 0) {
                        rawQuantityInput.max = availableForType;
                    } else {
                        rawQuantityInput.removeAttribute('max');
                    }
                }
                if (honeyTypeSelect) {
                    const rawOption = honeyTypeSelect.querySelector('option[value="raw"]');
                    const filteredOption = honeyTypeSelect.querySelector('option[value="filtered"]');
                    if (rawOption) {
                        rawOption.disabled = rawAvailable <= 0;
                    }
                    if (filteredOption) {
                        filteredOption.disabled = filteredAvailable <= 0;
                    }
                    if (honeyTypeSelect.value === 'filtered' && filteredAvailable <= 0 && rawAvailable > 0) {
                        honeyTypeSelect.value = 'raw';
                    }
                    if (honeyTypeSelect.value === 'raw' && rawAvailable <= 0 && filteredAvailable > 0) {
                        honeyTypeSelect.value = 'filtered';
                    }
                }
            } else {
                const available = parseFloat(option.dataset.available || '0');
                if (Number.isFinite(available) && available > 0) {
                    rawAvailability.textContent = `المتاح حالياً: ${available.toLocaleString('ar-EG', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${unit}`;
                    if (rawQuantityInput) {
                        rawQuantityInput.max = available;
                    }
                } else {
                    rawAvailability.textContent = 'لا يوجد رصيد متاح حالياً في المخزون.';
                    if (rawQuantityInput) {
                        rawQuantityInput.removeAttribute('max');
                    }
                }
            }
        };

        const hasRawOptions = (category) => {
            if (!rawDamageOptions || !category) {
                return false;
            }
            const items = rawDamageOptions[category];
            return Array.isArray(items) && items.length > 0;
        };

        if (rawCategorySelect) {
            let initialCategory = rawCategorySelect.value || 'honey';
            if (!hasRawOptions(initialCategory)) {
                const fallbackCategory = Array.from(rawCategorySelect.options)
                    .map(option => option.value)
                    .find(value => value && hasRawOptions(value));
                if (fallbackCategory) {
                    rawCategorySelect.value = fallbackCategory;
                    initialCategory = fallbackCategory;
                }
            }
            rawCategorySelect.addEventListener('change', function () {
                populateRawStocks(this.value);
                updateRawAvailability();
            });
            populateRawStocks(initialCategory);
        }
        if (rawStockSelect) {
            rawStockSelect.addEventListener('change', updateRawAvailability);
        }
        if (honeyTypeSelect) {
            honeyTypeSelect.addEventListener('change', updateRawAvailability);
        }
        updateRawAvailability();

        if (!periodSelect || !dayField) {
            if (params.get('section') === 'production_reports' && targetSection) {
                targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            return;
        }
        const toggleDayField = () => {
            if (periodSelect.value === 'day') {
                dayField.classList.remove('d-none');
            } else {
                dayField.classList.add('d-none');
            }
        };
        periodSelect.addEventListener('change', toggleDayField);
        toggleDayField();
        if (params.get('section') === 'production_reports' && targetSection) {
            targetSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
</script>

