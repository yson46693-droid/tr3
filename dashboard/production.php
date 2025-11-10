<?php
/**
 * لوحة التحكم لعمال الإنتاج
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_summary.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/path_helper.php';
require_once __DIR__ . '/../includes/production_helper.php';

requireRole(['production', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

$isTemplateAjax = ($page === 'production' && isset($_GET['ajax']));

if ($isTemplateAjax) {
    $ajaxAction = $_GET['ajax'];

    if ($ajaxAction === 'template_details' && isset($_GET['template_id'])) {
        header('Content-Type: application/json; charset=utf-8');

        $templateId = intval($_GET['template_id']);
        $templateType = $_GET['template_type'] ?? '';
        $templateTypeKey = 'legacy';
        $response = [
            'success' => false,
            'mode' => 'advanced',
            'components' => [],
            'hint' => 'يرجى اختيار المورد لكل مادة مستخدمة في التشغيلة.',
            'cache_key' => null
        ];

        try {
            $components = [];
            $templateType = trim($templateType);

            $addPackagingComponents = function($table, $templateId) use ($db, &$components) {
                $packagingQuantityColumn = 'quantity_per_unit';
                $packagingQuantityCheck = $db->queryOne("SHOW COLUMNS FROM {$table} LIKE 'quantity_per_unit'");
                if (!empty($packagingQuantityCheck)) {
                    $packagingQuantityColumn = 'quantity_per_unit';
                } elseif ($table === 'template_packaging') {
                    $legacyQuantityCheck = $db->queryOne("SHOW COLUMNS FROM template_packaging LIKE 'quantity'");
                    if (!empty($legacyQuantityCheck)) {
                        $packagingQuantityColumn = 'quantity';
                    }
                }

                $alias = $table === 'template_packaging' ? 'tp' : 'ptp';
                $hasUnitColumn = !empty($db->queryOne("SHOW COLUMNS FROM {$table} LIKE 'unit'"));
                $unitColumnSql = $hasUnitColumn ? "{$alias}.unit" : 'NULL';
                $packagingNameExpression = getColumnSelectExpression($table, 'packaging_name', 'packaging_name', $alias);
                $packagingItems = $db->query(
                    "SELECT {$alias}.id, {$alias}.packaging_material_id, {$packagingNameExpression}, {$alias}.{$packagingQuantityColumn} AS quantity,
                            COALESCE(pm.unit, {$unitColumnSql}, 'وحدة') AS unit
                     FROM {$table} {$alias}
                     LEFT JOIN packaging_materials pm ON pm.id = {$alias}.packaging_material_id
                     WHERE {$alias}.template_id = ?",
                    [$templateId]
                );

                foreach ($packagingItems as $item) {
                    $name = $item['packaging_name'] ?? 'أداة تعبئة';
                    $quantity = number_format((float)($item['quantity'] ?? 0), 3);
                    $unit = $item['unit'] ?? 'وحدة';
                    $components[] = [
                        'key' => 'pack_' . ($item['packaging_material_id'] ?? $item['id']),
                        'name' => $name,
                        'label' => 'مورد أداة التعبئة: ' . $name,
                        'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit,
                        'type' => 'packaging'
                    ];
                }
            };

            $templateTypeKey = $templateType !== '' ? $templateType : 'honey';

            switch ($templateTypeKey) {
                case 'unified':
                    $template = $db->queryOne(
                        "SELECT id, product_name FROM unified_product_templates WHERE id = ?",
                        [$templateId]
                    );
                    if (!$template) {
                        throw new Exception('القالب غير موجود أو تم حذفه.');
                    }

                    $addPackagingComponents('template_packaging', $templateId);

                    $rawQuantityColumn = 'quantity';
                    $rawQuantityCheck = $db->queryOne("SHOW COLUMNS FROM template_raw_materials LIKE 'quantity_per_unit'");
                    if (!empty($rawQuantityCheck)) {
                        $rawQuantityColumn = 'quantity_per_unit';
                    }

                    $rawMaterials = $db->query(
                        "SELECT id, material_name, material_type, {$rawQuantityColumn} AS quantity,
                                COALESCE(unit, 'وحدة') AS unit, honey_variety
                         FROM template_raw_materials
                         WHERE template_id = ?",
                        [$templateId]
                    );

                    foreach ($rawMaterials as $material) {
                        $name = $material['material_name'] ?? 'مادة خام';
                        $quantity = number_format((float)($material['quantity'] ?? 0), 3);
                        $unit = $material['unit'] ?? 'وحدة';
                        $extra = '';
                        if (!empty($material['honey_variety'])) {
                            $extra = ' - نوع: ' . $material['honey_variety'];
                        }
                        $components[] = [
                            'key' => 'raw_' . $material['id'],
                            'name' => $name,
                            'label' => 'مورد المادة: ' . $name,
                            'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit . $extra,
                            'type' => $material['material_type'] ?? '',
                            'honey_variety' => $material['honey_variety'] ?? null,
                            'requires_variety' => in_array($material['material_type'] ?? '', ['honey_raw', 'honey_filtered'], true)
                        ];
                    }

                    $response['hint'] = 'اختر مورد لكل مادة خام وأداة تعبئة مستخدمة في هذا القالب.';
                    break;

                case 'olive_oil':
                    $template = $db->queryOne(
                        "SELECT id, product_name, olive_oil_quantity FROM olive_oil_product_templates WHERE id = ?",
                        [$templateId]
                    );
                    if (!$template) {
                        throw new Exception('قالب زيت الزيتون غير موجود.');
                    }

                    $quantity = number_format((float)($template['olive_oil_quantity'] ?? 0), 3);
                    $components[] = [
                        'key' => 'olive_main',
                        'name' => 'زيت زيتون',
                        'label' => 'مورد زيت الزيتون',
                        'description' => 'الكمية لكل وحدة: ' . $quantity . ' لتر',
                        'type' => 'olive_oil'
                    ];

                    $addPackagingComponents('product_template_packaging', $templateId);
                    $response['hint'] = 'اختر المورد المسؤول عن زيت الزيتون وأي مواد إضافية متوفرة.';
                    break;

                case 'beeswax':
                    $template = $db->queryOne(
                        "SELECT id, product_name, beeswax_weight FROM beeswax_product_templates WHERE id = ?",
                        [$templateId]
                    );
                    if (!$template) {
                        throw new Exception('قالب شمع العسل غير موجود.');
                    }

                    $quantity = number_format((float)($template['beeswax_weight'] ?? 0), 3);
                    $components[] = [
                        'key' => 'beeswax_main',
                        'name' => 'شمع عسل',
                        'label' => 'مورد شمع العسل',
                        'description' => 'الكمية لكل وحدة: ' . $quantity . ' كجم',
                        'type' => 'beeswax'
                    ];

                    $addPackagingComponents('product_template_packaging', $templateId);
                    $response['hint'] = 'اختر المورد المسؤول عن شمع العسل وأي مواد إضافية متوفرة.';
                    break;

                case 'derivatives':
                    $template = $db->queryOne(
                        "SELECT id, product_name, derivative_type, derivative_weight FROM derivatives_product_templates WHERE id = ?",
                        [$templateId]
                    );
                    if (!$template) {
                        throw new Exception('قالب المشتقات غير موجود.');
                    }

                    $derivativeType = $template['derivative_type'] ?? 'مشتق';
                    $quantity = number_format((float)($template['derivative_weight'] ?? 0), 3);
                    $components[] = [
                        'key' => 'derivative_main',
                        'name' => $derivativeType,
                        'label' => 'مورد المشتق: ' . $derivativeType,
                        'description' => 'الكمية لكل وحدة: ' . $quantity . ' كجم',
                        'type' => 'derivatives'
                    ];

                    $addPackagingComponents('product_template_packaging', $templateId);
                    $response['hint'] = 'اختر المورد المسؤول عن المشتق وأي أدوات تعبئة مرتبطة.';
                    break;

                case 'honey':
                case 'legacy':
                default:
                    $template = $db->queryOne(
                        "SELECT id, product_name, honey_quantity FROM product_templates WHERE id = ?",
                        [$templateId]
                    );
                    if (!$template) {
                        throw new Exception('قالب المنتج غير موجود.');
                    }

                    if (isset($template['honey_quantity']) && (float)$template['honey_quantity'] > 0) {
                        $honeyQuantity = number_format((float)$template['honey_quantity'], 3);
                        $components[] = [
                            'key' => 'honey_main',
                            'name' => 'عسل',
                            'label' => 'مورد العسل',
                            'description' => 'الكمية لكل وحدة: ' . $honeyQuantity . ' جرام',
                            'type' => 'honey_filtered',
                            'requires_variety' => true
                        ];
                    }

                    $addPackagingComponents('product_template_packaging', $templateId);

                    $rawMaterials = $db->query(
                        "SELECT id, material_name, quantity_per_unit, unit
                         FROM product_template_raw_materials
                         WHERE template_id = ?",
                        [$templateId]
                    );

                    foreach ($rawMaterials as $material) {
                        $name = $material['material_name'] ?? 'مادة خام';
                        $quantity = number_format((float)($material['quantity_per_unit'] ?? 0), 3);
                        $unit = $material['unit'] ?? 'وحدة';
                        $components[] = [
                            'key' => 'raw_' . $material['id'],
                            'name' => $name,
                            'label' => 'مورد المادة: ' . $name,
                            'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit,
                            'type' => 'raw_general'
                        ];
                    }

                    $response['hint'] = 'اختر المورد لكل مكون (العسل، المواد الخام، أدوات التعبئة).';
                    break;
            }

            $response['components'] = $components;
            $response['success'] = true;
            $response['cache_key'] = $templateId . '::' . $templateTypeKey;
        } catch (Exception $e) {
            $response['success'] = false;
            $response['message'] = $e->getMessage();
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($ajaxAction === '1' && isset($_GET['id'])) {
        header('Content-Type: application/json; charset=utf-8');

        $productionId = intval($_GET['id']);

        $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
        $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
        $hasDateColumn = !empty($dateColumnCheck);
        $hasProductionDateColumn = !empty($productionDateColumnCheck);
        $dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');

        $userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
        $workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
        $hasUserIdColumn = !empty($userIdColumnCheck);
        $hasWorkerIdColumn = !empty($workerIdColumnCheck);
        $userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

        if ($userIdColumn) {
            $production = $db->queryOne(
                "SELECT p.*, pr.name as product_name, u.full_name as worker_name
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 LEFT JOIN users u ON p.{$userIdColumn} = u.id
                 WHERE p.id = ?",
                [$productionId]
            );
        } else {
            $production = $db->queryOne(
                "SELECT p.*, pr.name as product_name, 'غير محدد' as worker_name
                 FROM production p
                 LEFT JOIN products pr ON p.product_id = pr.id
                 WHERE p.id = ?",
                [$productionId]
            );
        }

        if ($production) {
            $production['date'] = $production[$dateColumn] ?? $production['created_at'] ?? date('Y-m-d');
            echo json_encode(['success' => true, 'production' => $production], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => 'سجل الإنتاج غير موجود'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

require_once __DIR__ . '/../includes/table_styles.php';

$isPackagingPost = (
    $page === 'packaging_warehouse'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
);

if ($isPackagingPost) {
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        include $modulePath;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'صفحة مخزن أدوات التعبئة غير متاحة حالياً.'
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pageStylesheets = isset($pageStylesheets) && is_array($pageStylesheets) ? $pageStylesheets : [];
if ($page === 'production') {
    $pageStylesheets[] = 'assets/css/production-page.css';
}

$isAjaxRequest = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (!empty($_POST['is_ajax'])) ||
    (isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

if ($isAjaxRequest) {
    $ajaxModulePath = null;

    if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
        $ajaxModulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    } elseif ($page === 'my_salary' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ajaxModulePath = __DIR__ . '/../modules/user/my_salary.php';
    }

    if ($ajaxModulePath && file_exists($ajaxModulePath)) {
        include $ajaxModulePath;
        exit;
    }
}

// معالجة AJAX القديمة لمخزن أدوات التعبئة (للتوافق)
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        include $modulePath;
        exit;
    }
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['production_dashboard']) ? $lang['production_dashboard'] : 'لوحة الإنتاج';
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <?php
                // التحقق من وجود جدول الإنتاج
                $hasProductionTable = !empty($db->queryOne("SHOW TABLES LIKE 'production'"));
                $hasAttendanceTable = !empty($db->queryOne("SHOW TABLES LIKE 'attendance'"));

                $dateColumn = 'created_at';
                $userIdColumn = null;
                $todayProduction = [];
                $monthStats = [
                    'total_production' => 0,
                    'total_quantity' => 0,
                    'total_workers' => 0
                ];
                $attendanceStats = [
                    'total_days' => 0,
                    'total_hours' => 0
                ];
                $activitySummary = [
                    'today_production' => 0,
                    'month_production' => 0,
                    'pending_tasks' => 0,
                    'recent_production' => []
                ];

                if ($hasProductionTable) {
                    // التحقق من وجود الأعمدة
                    $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
                    $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
                    $hasDateColumn = !empty($dateColumnCheck);
                    $hasProductionDateColumn = !empty($productionDateColumnCheck);
                    $dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');
                    
                    $userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
                    $workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
                    $hasUserIdColumn = !empty($userIdColumnCheck);
                    $hasWorkerIdColumn = !empty($workerIdColumnCheck);
                    $userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);

                    // الحصول على ملخص الأنشطة
                    $activitySummary = getProductionActivitySummary();

                    // إحصائيات الإنتاج اليومي
                    $dateExpression = $dateColumn === 'created_at' ? 'created_at' : $dateColumn;
                    if ($userIdColumn) {
                        $todayProduction = $db->query(
                            "SELECT p.*, pr.name as product_name, u.full_name as worker_name 
                             FROM production p 
                             LEFT JOIN products pr ON p.product_id = pr.id 
                             LEFT JOIN users u ON p.{$userIdColumn} = u.id 
                             WHERE DATE(p.{$dateExpression}) = CURDATE() 
                             ORDER BY p.created_at DESC 
                             LIMIT 10"
                        );
                    } else {
                        $todayProduction = $db->query(
                            "SELECT p.*, pr.name as product_name, 'غير محدد' as worker_name 
                             FROM production p 
                             LEFT JOIN products pr ON p.product_id = pr.id 
                             WHERE DATE(p.{$dateExpression}) = CURDATE() 
                             ORDER BY p.created_at DESC 
                             LIMIT 10"
                        );
                    }

                    // إحصائيات الإنتاج الشهري
                    if ($userIdColumn) {
                        $monthStats = $db->queryOne(
                            "SELECT 
                                COUNT(*) as total_production,
                                SUM(quantity) as total_quantity,
                                COUNT(DISTINCT {$userIdColumn}) as total_workers
                             FROM production 
                             WHERE MONTH({$dateExpression}) = MONTH(NOW()) 
                             AND YEAR({$dateExpression}) = YEAR(NOW()) 
                             AND status = 'approved'"
                        );
                    } else {
                        $monthStats = $db->queryOne(
                            "SELECT 
                                COUNT(*) as total_production,
                                SUM(quantity) as total_quantity,
                                0 as total_workers
                             FROM production 
                             WHERE MONTH({$dateExpression}) = MONTH(NOW()) 
                             AND YEAR({$dateExpression}) = YEAR(NOW()) 
                             AND status = 'approved'"
                        );
                    }
                }

                if ($hasAttendanceTable) {
                    $attendanceStats = $db->queryOne(
                        "SELECT 
                            COUNT(*) as total_days,
                            SUM(TIMESTAMPDIFF(HOUR, check_in, IFNULL(check_out, NOW()))) as total_hours
                         FROM attendance 
                         WHERE user_id = ? 
                         AND MONTH(date) = MONTH(NOW()) 
                         AND YEAR(date) = YEAR(NOW())
                         AND status = 'present'",
                        [$currentUser['id']]
                    );
                }

                $notifications = getUserNotifications($currentUser['id'], true, 10) ?? [];
                $tasksTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'tasks'"));
                $activeTaskTitles = [];

                if ($tasksTableExists) {
                    try {
                        $userTasks = $db->query(
                            "SELECT title FROM tasks 
                             WHERE assigned_to = ? 
                             AND status NOT IN ('completed', 'cancelled')",
                            [$currentUser['id']]
                        );

                        if (!empty($userTasks)) {
                            foreach ($userTasks as $taskRow) {
                                $title = trim((string)($taskRow['title'] ?? ''));
                                if ($title === '') {
                                    continue;
                                }
                                $normalized = mb_strtolower($title, 'UTF-8');
                                $activeTaskTitles[$normalized] = true;
                            }
                        }
                    } catch (Exception $taskLookupError) {
                        error_log('Dashboard task notification filter error: ' . $taskLookupError->getMessage());
                    }
                }

                $containsText = static function ($text, $needle) {
                    if ($text === '' || $needle === '') {
                        return false;
                    }
                    if (function_exists('mb_stripos')) {
                        return mb_stripos($text, $needle) !== false;
                    }
                    return stripos($text, $needle) !== false;
                };

                if (!empty($notifications)) {
                    $notifications = array_filter(
                        $notifications,
                        function ($notification) use ($db, $currentUser, $containsText, $tasksTableExists, $activeTaskTitles) {
                            $title = trim($notification['title'] ?? '');
                            $message = trim($notification['message'] ?? '');
                            $link = trim($notification['link'] ?? '');

                            $isCompletionAlert =
                                $containsText($message, 'كمكتملة') ||
                                $containsText($title, 'كمكتملة') ||
                                ($link !== '' && strpos($link, 'status=completed') !== false);

                            if ($isCompletionAlert) {
                                if (!empty($notification['id'])) {
                                    markNotificationAsRead((int)$notification['id'], (int)$currentUser['id']);
                                }
                                return false;
                            }

                            if (!empty($notification['id']) && ($containsText($title, 'تم إكمال') || $containsText($title, 'تم تحديث حالة'))) {
                                $titleSnippet = mb_substr($title, 0, 120);
                                $task = $db->queryOne(
                                    "SELECT status FROM tasks 
                                     WHERE assigned_to = ? 
                                     AND (
                                        title = ? 
                                        OR title LIKE CONCAT('%', ?) 
                                        OR ? LIKE CONCAT('%', title, '%')
                                     )
                                     ORDER BY updated_at DESC 
                                     LIMIT 1",
                                    [$currentUser['id'], $title, $titleSnippet, $title]
                                );

                                if ($task && $containsText((string)($task['status'] ?? ''), 'completed')) {
                                    markNotificationAsRead((int)$notification['id'], (int)$currentUser['id']);
                                    return false;
                                }
                            }

                            if ($tasksTableExists && $message !== '') {
                                $normalizedMessage = mb_strtolower($message, 'UTF-8');
                                $looksLikeTaskNotification =
                                    $containsText($title, 'مهمة') ||
                                    $containsText($title, 'task') ||
                                    $containsText($title, 'إدارة');

                                if ($looksLikeTaskNotification && !isset($activeTaskTitles[$normalizedMessage])) {
                                    if (!empty($notification['id'])) {
                                        markNotificationAsRead((int)$notification['id'], (int)$currentUser['id']);
                                    }
                                    return false;
                                }
                            }

                            return true;
                        }
                    );

                    if (!empty($notifications)) {
                        $notifications = array_slice(array_values($notifications), 0, 5);
                    }
                }
                ?>
                
                <div class="page-header mb-4">
                    <h2><i class="bi bi-speedometer2 me-2"></i><?php echo isset($lang['production_dashboard']) ? $lang['production_dashboard'] : 'لوحة الإنتاج'; ?></h2>
                </div>

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-bell me-2"></i><?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($notifications)): ?>
                        <div class="list-group">
                            <?php foreach ($notifications as $notif): ?>
                                <div class="list-group-item production-dashboard-notification" data-notification-id="<?php echo (int)($notif['id'] ?? 0); ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($notif['title'] ?? ''); ?></h6>
                                        <p class="mb-1"><?php echo htmlspecialchars($notif['message'] ?? ''); ?></p>
                                        <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($notif['created_at'] ?? 'now')); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <?php if (empty($notif['read'])): ?>
                                            <span class="badge bg-primary d-block mb-2"><?php echo isset($lang['new']) ? $lang['new'] : 'جديد'; ?></span>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mark-dashboard-notification" data-id="<?php echo (int)($notif['id'] ?? 0); ?>">
                                            <i class="bi bi-check2 me-1"></i>تم الرؤية
                                        </button>
                                    </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-center text-muted mb-0"><?php echo isset($lang['no_notifications']) ? $lang['no_notifications'] : 'لا توجد إشعارات حالياً'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                    window.initialNotifications = <?php echo json_encode($notifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                </script>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.mark-dashboard-notification').forEach(function(button) {
                            button.addEventListener('click', function() {
                                const notificationId = this.getAttribute('data-id');
                                if (!notificationId) {
                                    return;
                                }

                                const parentItem = this.closest('.production-dashboard-notification');
                                const listGroup = parentItem ? parentItem.parentElement : null;
                                deleteNotification(notificationId).then(function() {
                                    if (parentItem) {
                                        parentItem.remove();
                                    }
                                    if (listGroup && !listGroup.querySelector('.production-dashboard-notification')) {
                                        listGroup.innerHTML = '<p class="text-center text-muted mb-0">لا توجد إشعارات حالياً</p>';
                                    }
                                }).catch(function(err) {
                                    console.error('Mark notification as read failed:', err);
                                });
                            });
                        });
                    });
                </script>
                
                <!-- بطاقات الإحصائيات -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="dashboard-card">
                            <div class="card-icon">
                                <i class="bi bi-box-seam text-primary"></i>
                            </div>
                            <div class="card-title"><?php echo isset($lang['today_production']) ? $lang['today_production'] : 'إنتاج اليوم'; ?></div>
                            <div class="card-value"><?php echo $activitySummary['today_production'] ?? 0; ?></div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="dashboard-card">
                            <div class="card-icon">
                                <i class="bi bi-calendar-month text-success"></i>
                            </div>
                            <div class="card-title"><?php echo isset($lang['month_production']) ? $lang['month_production'] : 'إنتاج الشهر'; ?></div>
                            <div class="card-value"><?php echo $activitySummary['month_production'] ?? 0; ?></div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="dashboard-card">
                            <div class="card-icon">
                                <i class="bi bi-clock-history text-warning"></i>
                            </div>
                            <div class="card-title"><?php echo isset($lang['pending_tasks']) ? $lang['pending_tasks'] : 'المهام المعلقة'; ?></div>
                            <div class="card-value"><?php echo $activitySummary['pending_tasks'] ?? 0; ?></div>
                        </div>
                    </div>
                    
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="dashboard-card">
                            <div class="card-icon">
                                <i class="bi bi-hourglass-split text-info"></i>
                            </div>
                            <div class="card-title"><?php echo isset($lang['monthly_hours']) ? $lang['monthly_hours'] : 'ساعات الشهر'; ?></div>
                            <div class="card-value"><?php echo round($attendanceStats['total_hours'] ?? 0, 1); ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- إحصائيات إضافية -->
                <div class="row mb-4">
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h6 class="text-muted mb-3"><i class="bi bi-box-seam me-2"></i><?php echo isset($lang['total_quantity']) ? $lang['total_quantity'] : 'إجمالي الكمية الشهرية'; ?></h6>
                                <h3 class="mb-0"><?php echo number_format($monthStats['total_quantity'] ?? 0); ?></h3>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h6 class="text-muted mb-3"><i class="bi bi-people me-2"></i><?php echo isset($lang['total_workers']) ? $lang['total_workers'] : 'عدد العمال'; ?></h6>
                                <h3 class="mb-0"><?php echo $monthStats['total_workers'] ?? 0; ?></h3>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h6 class="text-muted mb-3"><i class="bi bi-calendar-check me-2"></i><?php echo isset($lang['attendance_days']) ? $lang['attendance_days'] : 'أيام الحضور'; ?></h6>
                                <h3 class="mb-0"><?php echo $attendanceStats['total_days'] ?? 0; ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- آخر الإنتاج -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i><?php echo isset($lang['recent_production']) ? $lang['recent_production'] : 'آخر الإنتاج'; ?></h5>
                        <?php 
                        $basePath = getBasePath();
                        $productionUrl = rtrim($basePath, '/') . '/dashboard/production.php?page=production';
                        ?>
                        <a href="<?php echo $productionUrl; ?>" class="btn btn-sm btn-light">
                            <i class="bi bi-arrow-left me-2"></i><?php echo isset($lang['view_all']) ? $lang['view_all'] : 'عرض الكل'; ?>
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?></th>
                                        <th><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?></th>
                                        <th><?php echo isset($lang['worker']) ? $lang['worker'] : 'العامل'; ?></th>
                                        <th><?php echo isset($lang['date']) ? $lang['date'] : 'التاريخ'; ?></th>
                                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($todayProduction)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">
                                                <?php echo isset($lang['no_production_today']) ? $lang['no_production_today'] : 'لا يوجد إنتاج لهذا اليوم'; ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($todayProduction as $prod): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($prod['product_name'] ?? 'غير محدد'); ?></td>
                                                <td><?php echo number_format($prod['quantity'] ?? 0); ?></td>
                                                <td><?php echo htmlspecialchars($prod['worker_name'] ?? 'غير محدد'); ?></td>
                                                <td><?php echo date('Y-m-d', strtotime($prod[$dateColumn] ?? $prod['created_at'] ?? 'now')); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($prod['status'] ?? 'pending') === 'approved' ? 'success' : (($prod['status'] ?? 'pending') === 'rejected' ? 'danger' : 'warning'); ?>">
                                                        <?php 
                                                        $status = $prod['status'] ?? 'pending';
                                                        echo isset($lang[$status]) ? $lang[$status] : ucfirst($status);
                                                        ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($page === 'production'): ?>
                <!-- صفحة إدارة الإنتاج -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/production.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة الإنتاج غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'tasks'): ?>
                <!-- صفحة إدارة المهام -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/tasks.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المهام غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'packaging_warehouse'): ?>
                <!-- صفحة مخزن أدوات التعبئة -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن أدوات التعبئة غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'raw_materials_warehouse'): ?>
                <!-- صفحة مخزن الخامات -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/raw_materials_warehouse.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة مخزن الخامات غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'honey_warehouse'): ?>
                <!-- إعادة توجيه من الرابط القديم -->
                <?php 
                header('Location: production.php?page=raw_materials_warehouse&section=honey');
                exit;
                ?>
                
            <?php elseif ($page === 'inventory'): ?>
                <!-- صفحة المنتجات النهائية -->
                <?php 
                $modulePath = __DIR__ . '/../modules/production/final_products.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                } else {
                    echo '<div class="alert alert-warning">صفحة المنتجات النهائية غير متاحة حالياً</div>';
                }
                ?>
                
            <?php elseif ($page === 'my_salary'): ?>
                <!-- صفحة مرتب المستخدم -->
                <?php 
                $modulePath = __DIR__ . '/../modules/user/my_salary.php';
                if (file_exists($modulePath)) {
                    include $modulePath;
                }
                ?>
                
            <?php endif; ?>

<script>
    // تمرير بيانات المستخدم للـ JavaScript
    window.currentUser = {
        id: <?php echo $currentUser['id']; ?>,
        role: '<?php echo htmlspecialchars($currentUser['role']); ?>'
    };
</script>
<script src="<?php echo ASSETS_URL; ?>js/attendance_notifications.js"></script>

<?php include __DIR__ . '/../templates/footer.php'; ?>

