<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';
require_once __DIR__ . '/../../includes/table_styles.php';

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
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'] ?? 'تم إرسال التقرير.';
        } else {
            $_SESSION['error_message'] = $result['message'] ?? 'تعذر إنشاء التقرير.';
        }
    }
    preventDuplicateSubmission(
        null,
        [
            'page' => 'reports',
            'section' => 'production_reports',
            'period' => $_GET['period'] ?? 'current_month',
            'date' => $_GET['date'] ?? $today
        ],
        null,
        'manager'
    );
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
    echo '<table class="table dashboard-table align-middle">';
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

<div class="d-flex justify-content-between align-items-center mb-4" id="productionReportsSection">
    <div>
        <h2 class="mb-1"><i class="bi bi-graph-up-arrow me-2"></i>تقارير الإنتاج</h2>
        <p class="text-muted mb-0">متابعة استهلاك أدوات التعبئة والمواد الخام</p>
    </div>
    <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
?>

<div class="card mb-4 shadow-sm">
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

<?php renderSummaryCards('ملخص ' . $rangeLabel, $selectedSummary); ?>

<?php renderDamageComplianceCard($selectedSummary['damage_compliance'] ?? []); ?>

<div class="card shadow-sm">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>الجدول الموحد للفترة: <?php echo htmlspecialchars($rangeLabel); ?></span>
        <span class="badge bg-primary-subtle text-primary">عدد السجلات: <?php echo number_format($recordsCount); ?></span>
    </div>
    <div class="card-body">
        <?php renderCombinedReportTable($combinedRows, $combinedTotals); ?>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const periodSelect = document.getElementById('reportPeriod');
        const dayField = document.getElementById('dayFilterField');
        const scopeSelect = document.getElementById('reportScopeSelect');
        const scopeDateField = document.getElementById('reportScopeDateField');
        const params = new URLSearchParams(window.location.search);
        const targetSection = document.getElementById('productionReportsSection');

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

