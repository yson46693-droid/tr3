<?php
/**
 * صفحة مخزن الخامات - المدير (عرض فقط)
 * Raw Materials Warehouse Page - Manager (View Only)
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();

// الحصول على القسم المطلوب
$section = $_GET['section'] ?? 'honey';
$validSections = ['honey', 'olive_oil', 'beeswax', 'derivatives', 'nuts'];
if (!in_array($section, $validSections)) {
    $section = 'honey';
}
?>

<style>
.section-tabs {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    padding: 15px;
    margin-bottom: 25px;
}

.section-tabs .nav-link {
    border-radius: 8px;
    margin: 0 5px;
    padding: 12px 20px;
    font-weight: 500;
    color: #6c757d;
    transition: all 0.3s ease;
}

.section-tabs .nav-link:hover {
    background: #f8f9fa;
    color: #0d6efd;
}

.section-tabs .nav-link.active {
    background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.4);
}

.section-tabs .nav-link i {
    margin-left: 8px;
    font-size: 1.1em;
}

.stats-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    transition: transform 0.3s ease;
    margin-bottom: 20px;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
}

.icon-honey { background: linear-gradient(135deg, #ffd89b 0%, #19547b 100%); }
.icon-olive { background: linear-gradient(135deg, #96e6a1 0%, #45b649 100%); }
.icon-wax { background: linear-gradient(135deg, #ffc371 0%, #ff5f6d 100%); }
.icon-derivative { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
.icon-nuts { background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%); }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-box-seam me-2"></i>مخزن الخامات (عرض فقط)</h2>
</div>

<!-- Tabs للأقسام -->
<div class="section-tabs">
    <ul class="nav nav-pills justify-content-center">
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'honey' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=honey">
                <i class="bi bi-droplet-fill"></i>العسل
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'olive_oil' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=olive_oil">
                <i class="bi bi-cup-straw"></i>زيت الزيتون
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'beeswax' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=beeswax">
                <i class="bi bi-hexagon-fill"></i>شمع العسل
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'nuts' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=nuts">
                <i class="bi bi-nut"></i>المكسرات
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $section === 'derivatives' ? 'active' : ''; ?>" 
               href="?page=raw_materials_warehouse&section=derivatives">
                <i class="bi bi-box2-fill"></i>المشتقات
            </a>
        </li>
    </ul>
</div>

<?php
if ($section === 'honey') {
    $honeyStats = [
        'total_raw' => $db->queryOne("SELECT COALESCE(SUM(raw_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
        'total_filtered' => $db->queryOne("SELECT COALESCE(SUM(filtered_honey_quantity), 0) as total FROM honey_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(DISTINCT supplier_id) as total FROM honey_stock")['total'] ?? 0
    ];
    
    $honeyStock = $db->query("
        SELECT hs.*, s.name as supplier_name, s.phone as supplier_phone
        FROM honey_stock hs
        LEFT JOIN suppliers s ON hs.supplier_id = s.id
        ORDER BY s.name ASC, hs.honey_variety ASC
    ");
    ?>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">العسل الخام</div>
                        <div class="h4 mb-0"><?php echo number_format($honeyStats['total_raw'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-honey">
                        <i class="bi bi-droplet-half"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">العسل المصفى</div>
                        <div class="h4 mb-0"><?php echo number_format($honeyStats['total_filtered'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-honey">
                        <i class="bi bi-droplet"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $honeyStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-honey">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">مخزون العسل</h5>
        </div>
        <div class="card-body">
            <?php if (empty($honeyStock)): ?>
                <div class="text-center text-muted py-5">لا يوجد مخزون</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>المورد</th>
                                <th>النوع</th>
                                <th class="text-center">العسل الخام</th>
                                <th class="text-center">العسل المصفى</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($honeyStock as $stock): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($stock['honey_variety']); ?></span></td>
                                    <td class="text-center"><strong class="text-warning"><?php echo number_format($stock['raw_honey_quantity'], 2); ?></strong> كجم</td>
                                    <td class="text-center"><strong class="text-success"><?php echo number_format($stock['filtered_honey_quantity'], 2); ?></strong> كجم</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
<?php
} elseif ($section === 'olive_oil') {
    $oilStats = [
        'total_quantity' => $db->queryOne("SELECT COALESCE(SUM(quantity), 0) as total FROM olive_oil_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(*) as total FROM olive_oil_stock")['total'] ?? 0,
        'templates_count' => $db->queryOne("SELECT COUNT(*) as total FROM olive_oil_product_templates")['total'] ?? 0
    ];
    
    $oilStock = $db->query("
        SELECT os.*, s.name as supplier_name
        FROM olive_oil_stock os
        LEFT JOIN suppliers s ON os.supplier_id = s.id
        ORDER BY s.name ASC
    ");
    
    $oilTemplates = $db->query("SELECT * FROM olive_oil_product_templates ORDER BY created_at DESC");
    ?>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي زيت الزيتون</div>
                        <div class="h4 mb-0"><?php echo number_format($oilStats['total_quantity'], 2); ?> <small>لتر</small></div>
                    </div>
                    <div class="stat-icon icon-olive">
                        <i class="bi bi-cup-straw"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $oilStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-olive">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">قوالب المنتجات</div>
                        <div class="h4 mb-0"><?php echo $oilStats['templates_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-olive">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">مخزون زيت الزيتون</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($oilStock)): ?>
                        <div class="text-center text-muted py-4">لا يوجد مخزون</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>المورد</th>
                                    <th class="text-center">الكمية (لتر)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($oilStock as $stock): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong></td>
                                        <td class="text-center"><strong class="text-success"><?php echo number_format($stock['quantity'], 2); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">قوالب المنتجات</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($oilTemplates)): ?>
                        <div class="text-center text-muted py-4">لا توجد قوالب</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>اسم المنتج</th>
                                    <th class="text-center">كمية الزيت (لتر)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($oilTemplates as $template): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($template['product_name']); ?></strong></td>
                                        <td class="text-center"><span class="badge bg-success"><?php echo number_format($template['olive_oil_quantity'], 2); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
<?php
} elseif ($section === 'beeswax') {
    $waxStats = [
        'total_weight' => $db->queryOne("SELECT COALESCE(SUM(weight), 0) as total FROM beeswax_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(*) as total FROM beeswax_stock")['total'] ?? 0,
        'templates_count' => $db->queryOne("SELECT COUNT(*) as total FROM beeswax_product_templates")['total'] ?? 0
    ];
    
    $waxStock = $db->query("
        SELECT ws.*, s.name as supplier_name
        FROM beeswax_stock ws
        LEFT JOIN suppliers s ON ws.supplier_id = s.id
        ORDER BY s.name ASC
    ");
    
    $waxTemplates = $db->query("SELECT * FROM beeswax_product_templates ORDER BY created_at DESC");
    ?>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي شمع العسل</div>
                        <div class="h4 mb-0"><?php echo number_format($waxStats['total_weight'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-wax">
                        <i class="bi bi-hexagon-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $waxStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-wax">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">قوالب المنتجات</div>
                        <div class="h4 mb-0"><?php echo $waxStats['templates_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-wax">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">مخزون شمع العسل</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($waxStock)): ?>
                        <div class="text-center text-muted py-4">لا يوجد مخزون</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>المورد</th>
                                    <th class="text-center">الوزن (كجم)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waxStock as $stock): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong></td>
                                        <td class="text-center"><strong class="text-warning"><?php echo number_format($stock['weight'], 2); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">قوالب المنتجات</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($waxTemplates)): ?>
                        <div class="text-center text-muted py-4">لا توجد قوالب</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>اسم المنتج</th>
                                    <th class="text-center">وزن الشمع (كجم)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waxTemplates as $template): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($template['product_name']); ?></strong></td>
                                        <td class="text-center"><span class="badge bg-warning text-dark"><?php echo number_format($template['beeswax_weight'], 2); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
<?php
} elseif ($section === 'nuts') {
    $nutsTableExists = $db->queryOne("SHOW TABLES LIKE 'nuts_stock'");
    $mixedTableExists = $db->queryOne("SHOW TABLES LIKE 'mixed_nuts'");
    $mixedIngredientsExists = $db->queryOne("SHOW TABLES LIKE 'mixed_nuts_ingredients'");
    
    if (empty($nutsTableExists)) {
        ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            لم يتم تفعيل مخزن المكسرات بعد. يرجى التواصل مع مسؤول النظام لإعداده.
        </div>
        <?php
    } else {
        $nutsStats = [
            'total_quantity' => $db->queryOne("SELECT COALESCE(SUM(quantity), 0) as total FROM nuts_stock")['total'] ?? 0,
            'suppliers_count' => $db->queryOne("SELECT COUNT(DISTINCT supplier_id) as total FROM nuts_stock")['total'] ?? 0,
            'types_count' => $db->queryOne("SELECT COUNT(DISTINCT nut_type) as total FROM nuts_stock")['total'] ?? 0,
            'mixed_batches' => !empty($mixedTableExists) ? ($db->queryOne("SELECT COUNT(*) as total FROM mixed_nuts")['total'] ?? 0) : 0
        ];
        
        $nutsStock = $db->query("
            SELECT ns.*, s.name as supplier_name, s.phone as supplier_phone
            FROM nuts_stock ns
            LEFT JOIN suppliers s ON ns.supplier_id = s.id
            ORDER BY ns.nut_type ASC, s.name ASC
        ");
        
        $mixedNuts = [];
        if (!empty($mixedTableExists) && !empty($mixedIngredientsExists)) {
            $mixedNuts = $db->query("
                SELECT mn.*, s.name as supplier_name, u.full_name as creator_name
                FROM mixed_nuts mn
                LEFT JOIN suppliers s ON mn.supplier_id = s.id
                LEFT JOIN users u ON mn.created_by = u.id
                ORDER BY mn.created_at DESC
            ");
            
            foreach ($mixedNuts as &$mix) {
                $mix['ingredients'] = $db->query("
                    SELECT mni.quantity, ns.nut_type
                    FROM mixed_nuts_ingredients mni
                    INNER JOIN nuts_stock ns ON mni.nuts_stock_id = ns.id
                    WHERE mni.mixed_nuts_id = ?
                ", [$mix['id']]);
            }
            unset($mix);
        }
        ?>
        
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">إجمالي الكمية</div>
                            <div class="h4 mb-0"><?php echo number_format($nutsStats['total_quantity'], 2); ?> <small>كجم</small></div>
                        </div>
                        <div class="stat-icon icon-nuts">
                            <i class="bi bi-nut"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">عدد الأنواع</div>
                            <div class="h4 mb-0"><?php echo $nutsStats['types_count']; ?></div>
                        </div>
                        <div class="stat-icon icon-nuts">
                            <i class="bi bi-tags"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">عدد الموردين</div>
                            <div class="h4 mb-0"><?php echo $nutsStats['suppliers_count']; ?></div>
                        </div>
                        <div class="stat-icon icon-nuts">
                            <i class="bi bi-people"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small mb-1">المكسرات المشكلة</div>
                            <div class="h4 mb-0"><?php echo $nutsStats['mixed_batches']; ?></div>
                        </div>
                        <div class="stat-icon icon-nuts">
                            <i class="bi bi-layers"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                        <h5 class="mb-0"><i class="bi bi-nut me-2"></i>المكسرات المنفردة</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($nutsStock)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                لا يوجد مخزون مكسرات
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>النوع</th>
                                            <th>المورد</th>
                                            <th class="text-center">الكمية (كجم)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($nutsStock as $stock): ?>
                                            <tr>
                                                <td><span class="badge" style="background-color: #8b6f47;"><?php echo htmlspecialchars($stock['nut_type']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($stock['supplier_name'] ?? 'غير محدد'); ?></strong>
                                                    <?php if (!empty($stock['supplier_phone'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($stock['supplier_phone']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><strong style="color: #8b6f47;"><?php echo number_format($stock['quantity'], 3); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header text-white" style="background: linear-gradient(135deg, #d4a574 0%, #8b6f47 100%);">
                        <h5 class="mb-0"><i class="bi bi-layers me-2"></i>المكسرات المشكلة</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($mixedNuts)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                                لا توجد مكسرات مشكلة
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($mixedNuts as $mix): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($mix['batch_name']); ?></h6>
                                                <small class="text-muted">
                                                    <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($mix['supplier_name'] ?? 'غير محدد'); ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-success"><?php echo number_format($mix['total_quantity'] ?? 0, 3); ?> كجم</span>
                                        </div>
                                        <?php if (!empty($mix['ingredients'])): ?>
                                            <div class="small">
                                                <strong>المكونات:</strong>
                                                <ul class="list-unstyled mb-0 mt-1">
                                                    <?php foreach ($mix['ingredients'] as $ingredient): ?>
                                                        <li class="text-muted">
                                                            • <?php echo htmlspecialchars($ingredient['nut_type']); ?>: <?php echo number_format($ingredient['quantity'], 3); ?> كجم
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($mix['notes'])): ?>
                                            <small class="text-muted d-block mt-2">
                                                <i class="bi bi-sticky me-1"></i><?php echo htmlspecialchars($mix['notes']); ?>
                                            </small>
                                        <?php endif; ?>
                                        <small class="text-muted d-block mt-1">
                                            <i class="bi bi-calendar me-1"></i><?php echo formatDateTime($mix['created_at']); ?>
                                            <?php if (!empty($mix['creator_name'])): ?>
                                                | <i class="bi bi-person-check me-1"></i><?php echo htmlspecialchars($mix['creator_name']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (empty($mixedTableExists) || empty($mixedIngredientsExists)): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                تم تفعيل عرض المكسرات، لكن جدول الخلطات غير متوفر حالياً.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
    <?php
    }
} elseif ($section === 'derivatives') {
    $derivStats = [
        'total_weight' => $db->queryOne("SELECT COALESCE(SUM(weight), 0) as total FROM derivatives_stock")['total'] ?? 0,
        'suppliers_count' => $db->queryOne("SELECT COUNT(DISTINCT supplier_id) as total FROM derivatives_stock")['total'] ?? 0,
        'types_count' => $db->queryOne("SELECT COUNT(DISTINCT derivative_type) as total FROM derivatives_stock")['total'] ?? 0,
        'templates_count' => $db->queryOne("SELECT COUNT(*) as total FROM derivatives_product_templates")['total'] ?? 0
    ];
    
    $derivStock = $db->query("
        SELECT ds.*, s.name as supplier_name
        FROM derivatives_stock ds
        LEFT JOIN suppliers s ON ds.supplier_id = s.id
        ORDER BY ds.derivative_type ASC, s.name ASC
    ");
    
    $derivTemplates = $db->query("SELECT * FROM derivatives_product_templates ORDER BY created_at DESC");
    ?>
    
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">إجمالي الوزن</div>
                        <div class="h4 mb-0"><?php echo number_format($derivStats['total_weight'], 2); ?> <small>كجم</small></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-box2-fill"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الأنواع</div>
                        <div class="h4 mb-0"><?php echo $derivStats['types_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-tags"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">عدد الموردين</div>
                        <div class="h4 mb-0"><?php echo $derivStats['suppliers_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stats-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small mb-1">قوالب المنتجات</div>
                        <div class="h4 mb-0"><?php echo $derivStats['templates_count']; ?></div>
                    </div>
                    <div class="stat-icon icon-derivative">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">مخزون المشتقات</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($derivStock)): ?>
                        <div class="text-center text-muted py-4">لا يوجد مخزون</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>النوع</th>
                                    <th>المورد</th>
                                    <th class="text-center">الوزن (كجم)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($derivStock as $stock): ?>
                                    <tr>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($stock['derivative_type']); ?></span></td>
                                        <td><strong><?php echo htmlspecialchars($stock['supplier_name']); ?></strong></td>
                                        <td class="text-center"><strong class="text-info"><?php echo number_format($stock['weight'], 2); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">قوالب المنتجات</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($derivTemplates)): ?>
                        <div class="text-center text-muted py-4">لا توجد قوالب</div>
                    <?php else: ?>
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>اسم المنتج</th>
                                    <th>نوع المشتق</th>
                                    <th class="text-center">الوزن (كجم)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($derivTemplates as $template): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($template['product_name']); ?></strong></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($template['derivative_type']); ?></span></td>
                                        <td class="text-center"><span class="badge bg-info"><?php echo number_format($template['derivative_weight'], 2); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php
}
?>

