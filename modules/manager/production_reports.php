<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';

requireRole('manager');

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$todaySummary = getConsumptionSummary($today, $today);
$monthSummary = getConsumptionSummary($monthStart, $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error_message'] = 'رمز الأمان غير صالح. يرجى إعادة المحاولة.';
    } else {
        $scope = $_POST['report_scope'] ?? 'daily';
        if ($scope === 'daily') {
            $result = sendConsumptionReport($today, $today, 'التقرير اليومي');
        } elseif ($scope === 'monthly') {
            $result = sendConsumptionReport($monthStart, $today, 'تقرير الشهر الحالي');
        } else {
            $result = ['success' => false, 'message' => 'نوع التقرير غير معروف.'];
        }
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'] ?? 'تم إرسال التقرير.';
        } else {
            $_SESSION['error_message'] = $result['message'] ?? 'تعذر إنشاء التقرير.';
        }
    }
    preventDuplicateSubmission(null, ['page' => 'production_reports'], null, 'manager');
}

function renderConsumptionTable($items, $includeCategory = false)
{
    if (empty($items)) {
        echo '<div class="table-responsive"><div class="text-center text-muted py-4">لا توجد بيانات</div></div>';
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
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover align-middle">';
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

    echo '<div class="table-responsive">';
    echo '<table class="table table-hover align-middle">';
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

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><i class="bi bi-graph-up-arrow me-2"></i>تقارير الإنتاج</h2>
        <p class="text-muted mb-0">متابعة استهلاك أدوات التعبئة والمواد الخام</p>
    </div>
    <div class="d-flex gap-2">
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="report_scope" value="daily">
            <button class="btn btn-primary">
                <i class="bi bi-send-check me-1"></i>إرسال تقرير اليوم
            </button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="report_scope" value="monthly">
            <button class="btn btn-outline-primary">
                <i class="bi bi-send-fill me-1"></i>إرسال تقرير الشهر
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

renderSummaryCards('تقرير اليوم', $todaySummary);
?>

<div class="card mb-4 shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة المستهلكة اليوم</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($todaySummary['packaging']['items']); ?>
    </div>
</div>
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-octagon me-2"></i>التالف من أدوات التعبئة اليوم</span>
        <span class="badge bg-light text-dark">الإجمالي: <?php echo number_format($todaySummary['packaging_damage']['total'], 3); ?></span>
    </div>
    <div class="card-body">
        <?php renderPackagingDamageTable($todaySummary['packaging_damage']['items']); ?>
    </div>
</div>
<div class="card mb-5 shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-droplet-half me-2"></i>المواد الخام المستهلكة اليوم</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($todaySummary['raw']['items'], true); ?>
    </div>
</div>

<?php
renderSummaryCards('تقرير الشهر الحالي', $monthSummary);
?>

<div class="card mb-4 shadow-sm">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة للشهر الحالي</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($monthSummary['packaging']['items']); ?>
    </div>
</div>
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-octagon me-2"></i>التالف من أدوات التعبئة للشهر الحالي</span>
        <span class="badge bg-light text-dark">الإجمالي: <?php echo number_format($monthSummary['packaging_damage']['total'], 3); ?></span>
    </div>
    <div class="card-body">
        <?php renderPackagingDamageTable($monthSummary['packaging_damage']['items']); ?>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-droplet-half me-2"></i>المواد الخام للشهر الحالي</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($monthSummary['raw']['items'], true); ?>
    </div>
</div>

