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

requireRole('production');

$currentUser = getCurrentUser();
$db = db();
$page = $_GET['page'] ?? 'dashboard';

// معالجة AJAX قبل أي إخراج HTML - خاصة لصفحة مخزن أدوات التعبئة
if ($page === 'packaging_warehouse' && isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['material_id'])) {
    // تحميل ملف packaging_warehouse.php مباشرة للتعامل مع AJAX
    $modulePath = __DIR__ . '/../modules/production/packaging_warehouse.php';
    if (file_exists($modulePath)) {
        // الملف نفسه سيتعامل مع AJAX ويخرج JSON
        include $modulePath;
        exit; // إيقاف التنفيذ بعد معالجة AJAX
    }
}

require_once __DIR__ . '/../includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
$pageTitle = isset($lang['production_dashboard']) ? $lang['production_dashboard'] : 'لوحة الإنتاج';
?>
<?php include __DIR__ . '/../templates/header.php'; ?>

            <?php if ($page === 'dashboard' || $page === ''): ?>
                <?php
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
                
                // الحصول على ملخص الأنشطة
                $activitySummary = getProductionActivitySummary();
                
                // إحصائيات الإنتاج اليومي
                if ($userIdColumn) {
                    $todayProduction = $db->query(
                        "SELECT p.*, pr.name as product_name, u.full_name as worker_name 
                         FROM production p 
                         LEFT JOIN products pr ON p.product_id = pr.id 
                         LEFT JOIN users u ON p.{$userIdColumn} = u.id 
                         WHERE DATE(p.{$dateColumn}) = CURDATE() 
                         ORDER BY p.created_at DESC 
                         LIMIT 10"
                    );
                } else {
                    $todayProduction = $db->query(
                        "SELECT p.*, pr.name as product_name, 'غير محدد' as worker_name 
                         FROM production p 
                         LEFT JOIN products pr ON p.product_id = pr.id 
                         WHERE DATE(p.{$dateColumn}) = CURDATE() 
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
                         WHERE MONTH({$dateColumn}) = MONTH(NOW()) 
                         AND YEAR({$dateColumn}) = YEAR(NOW()) 
                         AND status = 'approved'"
                    );
                } else {
                    $monthStats = $db->queryOne(
                        "SELECT 
                            COUNT(*) as total_production,
                            SUM(quantity) as total_quantity,
                            0 as total_workers
                         FROM production 
                         WHERE MONTH({$dateColumn}) = MONTH(NOW()) 
                         AND YEAR({$dateColumn}) = YEAR(NOW()) 
                         AND status = 'approved'"
                    );
                }
                
                // إحصائيات الحضور
                $attendanceStats = $db->queryOne(
                    "SELECT 
                        COUNT(*) as total_days,
                        SUM(TIMESTAMPDIFF(HOUR, check_in, check_out)) as total_hours
                     FROM attendance 
                     WHERE user_id = ? 
                     AND MONTH(date) = MONTH(NOW()) 
                     AND YEAR(date) = YEAR(NOW())
                     AND status = 'present'",
                    [$currentUser['id']]
                );
                ?>
                
                <div class="page-header mb-4">
                    <h2><i class="bi bi-speedometer2 me-2"></i><?php echo isset($lang['production_dashboard']) ? $lang['production_dashboard'] : 'لوحة الإنتاج'; ?></h2>
                </div>
                
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
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
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
                
                <!-- الإشعارات -->
                <?php
                $notifications = $db->query(
                    "SELECT * FROM notifications 
                     WHERE user_id = ? OR role = ? 
                     ORDER BY created_at DESC 
                     LIMIT 5",
                    [$currentUser['id'], $currentUser['role']]
                );
                ?>
                
                <?php if (!empty($notifications)): ?>
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-bell me-2"></i><?php echo isset($lang['notifications']) ? $lang['notifications'] : 'الإشعارات'; ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="list-group">
                            <?php foreach ($notifications as $notif): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($notif['title'] ?? ''); ?></h6>
                                            <p class="mb-1"><?php echo htmlspecialchars($notif['message'] ?? ''); ?></p>
                                            <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($notif['created_at'] ?? 'now')); ?></small>
                                        </div>
                                        <?php if (empty($notif['read_at'])): ?>
                                            <span class="badge bg-primary"><?php echo isset($lang['new']) ? $lang['new'] : 'جديد'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
            <?php elseif ($page === 'production'): ?>
                <!-- صفحة إدارة الإنتاج -->
                <?php 
                // معالجة AJAX لتحميل تفاصيل القوالب المتقدمة
                if (isset($_GET['ajax']) && $_GET['ajax'] === 'template_details' && isset($_GET['template_id'])) {
                    header('Content-Type: application/json; charset=utf-8');
                    $templateId = intval($_GET['template_id']);
                    $response = [
                        'success' => false,
                        'mode' => 'legacy',
                        'components' => []
                    ];

                    try {
                        $unifiedTemplate = $db->queryOne(
                            "SELECT id, product_name FROM unified_product_templates WHERE id = ?",
                            [$templateId]
                        );

                        if ($unifiedTemplate) {
                            $components = [];

                            $packagingQuantityColumn = 'quantity_per_unit';
                            $packagingQuantityCheck = $db->queryOne("SHOW COLUMNS FROM template_packaging LIKE 'quantity_per_unit'");
                            if (empty($packagingQuantityCheck)) {
                                $packagingQuantityColumn = 'quantity';
                            }

                            $packagingNameExpression = getColumnSelectExpression('template_packaging', 'packaging_name', 'packaging_name', 'tp');
                            $packagingItems = $db->query(
                                "SELECT tp.id, tp.packaging_material_id, {$packagingNameExpression}, tp.{$packagingQuantityColumn} AS quantity, 
                                        COALESCE(pm.unit, tp.unit, 'وحدة') AS unit
                                 FROM template_packaging tp
                                 LEFT JOIN packaging_materials pm ON pm.id = tp.packaging_material_id
                                 WHERE tp.template_id = ?",
                                [$templateId]
                            );

                            foreach ($packagingItems as $item) {
                                $name = $item['packaging_name'] ?? 'مادة تعبئة';
                                $quantity = number_format((float)($item['quantity'] ?? 0), 3);
                                $unit = $item['unit'] ?? 'وحدة';
                                $components[] = [
                                    'key' => 'pack_' . ($item['packaging_material_id'] ?? $item['id']),
                                    'name' => $name,
                                    'label' => 'أداة تعبئة: ' . $name,
                                    'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit
                                ];
                            }

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
                                    'label' => 'مادة خام: ' . $name,
                                    'description' => 'الكمية لكل وحدة: ' . $quantity . ' ' . $unit . $extra,
                                    'type' => $material['material_type'] ?? ''
                                ];
                            }

                            $response['success'] = true;
                            $response['mode'] = 'advanced';
                            $response['components'] = $components;
                        } else {
                            $response['success'] = true;
                            $response['mode'] = 'legacy';
                        }
                    } catch (Exception $e) {
                        $response['success'] = false;
                        $response['message'] = $e->getMessage();
                    }

                    echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    exit;
                }

                // معالجة AJAX لتحميل بيانات الإنتاج
                if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['id'])) {
                    header('Content-Type: application/json');
                    $productionId = intval($_GET['id']);
                    
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
                        echo json_encode(['success' => true, 'production' => $production]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'سجل الإنتاج غير موجود']);
                    }
                    exit;
                }
                
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

