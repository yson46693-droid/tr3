<?php
/**
 * صفحة الأمان والصلاحيات للمدير
 */

// تعيين ترميز UTF-8
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/request_monitor.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة عمليات الصلاحيات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'grant_permission') {
        $userId = intval($_POST['user_id'] ?? 0);
        $permissionId = intval($_POST['permission_id'] ?? 0);
        
        if ($userId > 0 && $permissionId > 0) {
            // التحقق من وجود الجدول أولاً
            $tableCheck = $db->queryOne("SHOW TABLES LIKE 'user_permissions'");
            if (empty($tableCheck)) {
                // إنشاء الجدول إذا لم يكن موجوداً
                try {
                    $db->execute("
                        CREATE TABLE IF NOT EXISTS `user_permissions` (
                          `id` int(11) NOT NULL AUTO_INCREMENT,
                          `user_id` int(11) NOT NULL,
                          `permission_id` int(11) NOT NULL,
                          `granted` tinyint(1) DEFAULT 1,
                          `granted_by` int(11) DEFAULT NULL,
                          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          PRIMARY KEY (`id`),
                          UNIQUE KEY `user_permission` (`user_id`,`permission_id`),
                          KEY `permission_id` (`permission_id`),
                          KEY `granted_by` (`granted_by`),
                          CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                          CONSTRAINT `user_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
                          CONSTRAINT `user_permissions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                } catch (Exception $e) {
                    error_log("Error creating user_permissions table: " . $e->getMessage());
                }
            }
            
            // التحقق من وجود عمود granted_by وإضافته إذا لم يكن موجوداً
            $grantedByColumnCheck = $db->queryOne("SHOW COLUMNS FROM user_permissions LIKE 'granted_by'");
            if (empty($grantedByColumnCheck)) {
                try {
                    $db->execute("ALTER TABLE user_permissions ADD COLUMN `granted_by` int(11) DEFAULT NULL AFTER `granted`");
                    $db->execute("ALTER TABLE user_permissions ADD KEY `granted_by` (`granted_by`)");
                    $db->execute("ALTER TABLE user_permissions ADD CONSTRAINT `user_permissions_ibfk_3` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL");
                } catch (Exception $e) {
                    error_log("Error adding granted_by column: " . $e->getMessage());
                }
            }
            
            if (grantPermission($userId, $permissionId)) {
                logAudit($currentUser['id'], 'grant_permission', 'user_permission', $userId, null, ['permission_id' => $permissionId]);
                $success = 'تم منح الصلاحية بنجاح';
            } else {
                $error = 'حدث خطأ في منح الصلاحية. يرجى التحقق من سجل الأخطاء.';
            }
        } else {
            $error = 'معرف المستخدم أو الصلاحية غير صحيح';
        }
    } elseif ($action === 'revoke_permission') {
        $userId = intval($_POST['user_id'] ?? 0);
        $permissionId = intval($_POST['permission_id'] ?? 0);
        
        if ($userId > 0 && $permissionId > 0) {
            if (revokePermission($userId, $permissionId)) {
                logAudit($currentUser['id'], 'revoke_permission', 'user_permission', $userId, ['permission_id' => $permissionId], null);
                $success = 'تم إلغاء الصلاحية بنجاح';
            } else {
                $error = 'حدث خطأ في إلغاء الصلاحية. يرجى التحقق من سجل الأخطاء.';
            }
        } else {
            $error = 'معرف المستخدم أو الصلاحية غير صحيح';
        }
    } elseif ($action === 'block_ip') {
        $ipAddress = trim($_POST['ip_address'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $durationMinutes = intval($_POST['duration_minutes'] ?? 0);

        if (empty($ipAddress) || !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            $error = 'عنوان IP غير صالح.';
        } else {
            $blockedUntil = null;
            if ($durationMinutes > 0) {
                $blockedUntil = date('Y-m-d H:i:s', time() + ($durationMinutes * 60));
            }

            if (blockIP($ipAddress, $reason ?: 'نشاط مرتفع', $blockedUntil, $currentUser['id'] ?? null)) {
                $success = 'تم حظر عنوان IP بنجاح.';
            } else {
                $error = 'تعذر حظر عنوان IP المحدد.';
            }
        }

        $_GET['tab'] = 'usage';
    } elseif ($action === 'cleanup_usage') {
        $usageUserId = intval($_POST['usage_user_id'] ?? 0);
        $usageDateParam = $_POST['usage_date'] ?? null;

        if ($usageUserId > 0) {
            $deleted = deleteRequestUsageForUser($usageUserId, $usageDateParam);
            if ($deleted > 0) {
                $success = 'تم حذف ' . $deleted . ' سجل استخدام للمستخدم المحدد.';
            } else {
                $success = 'لا توجد سجلات استخدام لحذفها للمستخدم المحدد.';
            }
        } else {
            $days = intval($_POST['days'] ?? 30);
            $deleted = cleanupRequestUsage($days);
            $success = 'تم حذف ' . $deleted . ' سجل استخدام قديم.';
        }
        $_GET['tab'] = 'usage';
    }
}

// التحقق من وجود جدول permissions وإنشاؤه إذا لم يكن موجوداً
$permissionsTableCheck = $db->queryOne("SHOW TABLES LIKE 'permissions'");
if (empty($permissionsTableCheck)) {
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `permissions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(100) NOT NULL,
              `description` text DEFAULT NULL,
              `category` varchar(50) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `name` (`name`),
              KEY `category` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // إدراج بعض الصلاحيات الأساسية إذا كان الجدول فارغاً
        $existingPerms = $db->query("SELECT COUNT(*) as count FROM permissions");
        if (!empty($existingPerms) && $existingPerms[0]['count'] == 0) {
            $defaultPermissions = [
                ['name' => 'view_dashboard', 'description' => 'عرض لوحة التحكم', 'category' => 'dashboard'],
                ['name' => 'manage_users', 'description' => 'إدارة المستخدمين', 'category' => 'management'],
                ['name' => 'manage_permissions', 'description' => 'إدارة الصلاحيات', 'category' => 'management'],
                ['name' => 'view_reports', 'description' => 'عرض التقارير', 'category' => 'reports'],
                ['name' => 'manage_financial', 'description' => 'إدارة المالية', 'category' => 'financial'],
                ['name' => 'manage_inventory', 'description' => 'إدارة المخزون', 'category' => 'inventory'],
                ['name' => 'manage_sales', 'description' => 'إدارة المبيعات', 'category' => 'sales'],
                ['name' => 'manage_production', 'description' => 'إدارة الإنتاج', 'category' => 'production'],
            ];
            
            foreach ($defaultPermissions as $perm) {
                try {
                    $db->execute(
                        "INSERT INTO permissions (name, description, category) VALUES (?, ?, ?)",
                        [$perm['name'], $perm['description'], $perm['category']]
                    );
                } catch (Exception $e) {
                    error_log("Error inserting default permission: " . $e->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error creating permissions table: " . $e->getMessage());
    }
}

// الحصول على بيانات الصلاحيات
$users = $db->query("SELECT id, username, full_name, role FROM users WHERE status = 'active' ORDER BY role, username");
$permissions = getAllPermissions();
$selectedUserId = $_GET['user_id'] ?? ($users[0]['id'] ?? null);
$userPermissions = $selectedUserId ? getUserPermissions($selectedUserId) : [];

// بيانات مراقبة الاستخدام
$usageDate = $_GET['usage_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $usageDate)) {
    $usageDate = date('Y-m-d');
}
$usageLimit = isset($_GET['usage_limit']) ? max(10, min(200, intval($_GET['usage_limit']))) : 50;
$selectedUsageUserId = isset($_GET['usage_user_id']) ? max(0, intval($_GET['usage_user_id'])) : null;

$requestUsageUsers = getRequestUsageSummary([
    'type' => 'user',
    'date' => $usageDate,
    'limit' => $usageLimit,
]);
$requestUsageIps = getRequestUsageSummary([
    'type' => 'ip',
    'date' => $usageDate,
    'limit' => $usageLimit,
]);
$requestUsageAlerts = getRequestUsageAlerts($usageDate, max(50, $usageLimit));
$selectedUsageUserDetails = $selectedUsageUserId ? getRequestUsageDetailsForUser($selectedUsageUserId, $usageDate) : [];
$selectedUsageUser = null;

if ($selectedUsageUserId) {
    foreach ($requestUsageUsers as $usageRow) {
        if (intval($usageRow['user_id']) === intval($selectedUsageUserId)) {
            $selectedUsageUser = $usageRow;
            break;
        }
    }
}

$usageThresholdUser = defined('REQUEST_USAGE_THRESHOLD_PER_USER') ? REQUEST_USAGE_THRESHOLD_PER_USER : null;
$usageThresholdIp = defined('REQUEST_USAGE_THRESHOLD_PER_IP') ? REQUEST_USAGE_THRESHOLD_PER_IP : null;

// الحصول على بيانات الأمان
$blockedIPs = getBlockedIPs();
$stats = getLoginAttemptsStats();
$loginAttempts = getLoginAttempts([], 20);

// بيانات سجل التدقيق
$auditPerPage = isset($_GET['audit_limit']) ? max(5, min(50, intval($_GET['audit_limit']))) : 20;
$auditPage = isset($_GET['audit_page']) ? max(1, intval($_GET['audit_page'])) : 1;
$auditUserFilter = isset($_GET['audit_user']) ? max(0, intval($_GET['audit_user'])) : 0;
$auditActionFilter = isset($_GET['audit_action']) ? trim($_GET['audit_action']) : '';
$auditEntityFilter = isset($_GET['audit_entity']) ? trim($_GET['audit_entity']) : '';
$auditDateFrom = isset($_GET['audit_date_from']) ? trim($_GET['audit_date_from']) : '';
$auditDateTo = isset($_GET['audit_date_to']) ? trim($_GET['audit_date_to']) : '';

if ($auditUserFilter <= 0) {
    $auditUserFilter = null;
}

if ($auditActionFilter === '') {
    $auditActionFilter = null;
}

if ($auditEntityFilter === '') {
    $auditEntityFilter = null;
}

if ($auditDateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDateFrom)) {
    $auditDateFrom = null;
}

if ($auditDateTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditDateTo)) {
    $auditDateTo = null;
}

$auditFilters = [];

if (!empty($auditUserFilter)) {
    $auditFilters['user_id'] = $auditUserFilter;
}
if ($auditActionFilter) {
    $auditFilters['action'] = $auditActionFilter;
}
if ($auditEntityFilter) {
    $auditFilters['entity_type'] = $auditEntityFilter;
}
if ($auditDateFrom) {
    $auditFilters['date_from'] = $auditDateFrom;
}
if ($auditDateTo) {
    $auditFilters['date_to'] = $auditDateTo;
}

$auditOffset = ($auditPage - 1) * $auditPerPage;
$auditTotal = getAuditLogsCount($auditFilters);
$auditLogs = $auditTotal > 0 ? getAuditLogs($auditFilters, $auditPerPage, $auditOffset) : [];
$auditTotalPages = max(1, (int)ceil($auditTotal / $auditPerPage));

$auditUsersList = $db->query("SELECT id, username, full_name FROM users WHERE status = 'active' ORDER BY username ASC");
$auditActionsList = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
$auditEntitiesList = $db->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type <> '' ORDER BY entity_type ASC");

$auditQueryBase = [
    'page' => 'security',
    'tab' => 'audit',
    'audit_limit' => $auditPerPage,
];

if (!empty($auditUserFilter)) {
    $auditQueryBase['audit_user'] = $auditUserFilter;
}
if ($auditActionFilter) {
    $auditQueryBase['audit_action'] = $auditActionFilter;
}
if ($auditEntityFilter) {
    $auditQueryBase['audit_entity'] = $auditEntityFilter;
}
if ($auditDateFrom) {
    $auditQueryBase['audit_date_from'] = $auditDateFrom;
}
if ($auditDateTo) {
    $auditQueryBase['audit_date_to'] = $auditDateTo;
}

// تحديد التاب الافتراضي
$activeTab = $_GET['tab'] ?? 'security';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shield-lock me-2"></i>الأمان والصلاحيات</h2>
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

<!-- Bootstrap Tabs -->
<style>
#securityTabs {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    border-bottom: none;
}

#securityTabs .nav-item {
    flex: 1 1 180px;
}

#securityTabs .nav-link {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    border-radius: 0.75rem;
    border: 1px solid transparent;
    background-color: var(--bs-light);
    color: var(--bs-body-color);
    font-weight: 600;
    padding: 0.75rem 1rem;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    transition: all 0.2s ease-in-out;
}

#securityTabs .nav-link i {
    font-size: 1.1rem;
}

#securityTabs .nav-link:not(.active):hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(15, 23, 42, 0.12);
}

#securityTabs .nav-link.active {
    background-color: var(--bs-primary);
    color: #fff;
    border-color: var(--bs-primary);
    box-shadow: 0 6px 18px rgba(37, 99, 235, 0.25);
}

.tab-content .card {
    margin-bottom: 1.5rem;
}

@media (max-width: 992px) {
    #securityTabs .nav-item {
        flex: 1 1 160px;
    }
}

@media (max-width: 768px) {
    #securityTabs {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 0.75rem;
    }
    
    #securityTabs .nav-item {
        width: 100%;
        margin-bottom: 0;
    }
    
    #securityTabs .nav-link {
        font-size: 0.95rem;
        padding: 0.65rem 0.75rem;
        text-align: center;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th,
    .table td {
        padding: 0.5rem;
    }
}
</style>

<ul class="nav nav-tabs mb-4" id="securityTabs" role="tablist">
    <li class="nav-item flex-shrink-0" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'security' ? 'active' : ''; ?>" 
                id="security-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#security-content" 
                type="button" 
                role="tab" 
                aria-controls="security-content" 
                aria-selected="<?php echo $activeTab === 'security' ? 'true' : 'false'; ?>">
            <i class="bi bi-shield-lock me-2"></i><span>الأمان</span>
        </button>
    </li>
    <li class="nav-item flex-shrink-0" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'usage' ? 'active' : ''; ?>"
                id="usage-tab"
                data-bs-toggle="tab"
                data-bs-target="#usage-content"
                type="button"
                role="tab"
                aria-controls="usage-content"
                aria-selected="<?php echo $activeTab === 'usage' ? 'true' : 'false'; ?>">
            <i class="bi bi-activity me-2"></i><span>مراقبة الاستخدام</span>
        </button>
    </li>
    <li class="nav-item flex-shrink-0" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'audit' ? 'active' : ''; ?>"
                id="audit-tab"
                data-bs-toggle="tab"
                data-bs-target="#audit-content"
                type="button"
                role="tab"
                aria-controls="audit-content"
                aria-selected="<?php echo $activeTab === 'audit' ? 'true' : 'false'; ?>">
            <i class="bi bi-journal-text me-2"></i><span>سجل التدقيق</span>
        </button>
    </li>
    <li class="nav-item flex-shrink-0" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'users' ? 'active' : ''; ?>" 
                id="users-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#users-content" 
                type="button" 
                role="tab" 
                aria-controls="users-content" 
                aria-selected="<?php echo $activeTab === 'users' ? 'true' : 'false'; ?>">
            <i class="bi bi-people me-2"></i><span>المستخدمون</span>
        </button>
    </li>
    <li class="nav-item flex-shrink-0" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'permissions' ? 'active' : ''; ?>" 
                id="permissions-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#permissions-content" 
                type="button" 
                role="tab" 
                aria-controls="permissions-content" 
                aria-selected="<?php echo $activeTab === 'permissions' ? 'true' : 'false'; ?>">
            <i class="bi bi-shield-check me-2"></i><span>الصلاحيات</span>
        </button>
    </li>
    <li class="nav-item flex-shrink-0" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'backup' ? 'active' : ''; ?>" 
                id="backup-tab" 
                data-bs-toggle="tab" 
                data-bs-target="#backup-content" 
                type="button" 
                role="tab" 
                aria-controls="backup-content" 
                aria-selected="<?php echo $activeTab === 'backup' ? 'true' : 'false'; ?>">
            <i class="bi bi-hdd-stack me-2"></i><span>النسخ الاحتياطي</span>
        </button>
    </li>
</ul>

<div class="tab-content" id="securityTabsContent">
    <!-- Tab: الأمان -->
    <div class="tab-pane fade <?php echo $activeTab === 'security' ? 'show active' : ''; ?>" 
         id="security-content" 
         role="tabpanel" 
         aria-labelledby="security-tab">
        <div class="row">
            <div class="col-12 col-lg-6 mb-3 mb-lg-0">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-ban me-2"></i>IP المحظورة</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle">
                                <thead>
                                    <tr>
                                        <th>عنوان IP</th>
                                        <th class="d-none d-md-table-cell">السبب</th>
                                        <th class="d-none d-lg-table-cell">حتى</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($blockedIPs)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted">لا توجد IP محظورة</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($blockedIPs as $ip): ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($ip['ip_address']); ?></code></td>
                                                <td class="d-none d-md-table-cell">
                                                    <small><?php echo htmlspecialchars($ip['reason'] ?? '-'); ?></small>
                                                </td>
                                                <td class="d-none d-lg-table-cell">
                                                    <small><?php echo $ip['blocked_until'] ? formatDateTime($ip['blocked_until']) : 'دائم'; ?></small>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-success" 
                                                            onclick="unblockIP('<?php echo htmlspecialchars($ip['ip_address'], ENT_QUOTES); ?>', event)"
                                                            title="إلغاء الحظر">
                                                        <i class="bi bi-unlock"></i>
                                                        <span class="d-none d-sm-inline">إلغاء الحظر</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-white d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                        <h5 class="mb-0"><i class="bi bi-key me-2"></i>محاولات تسجيل الدخول</h5>
                        <div class="d-flex flex-column flex-md-row gap-2 align-items-start align-items-md-center">
                            <small class="text-white-50">
                                <span class="d-block d-md-inline">إجمالي: <?php echo $stats['total']; ?></span>
                                <span class="d-block d-md-inline ms-md-2">اليوم: <?php echo $stats['today']; ?></span>
                                <span class="d-block d-md-inline ms-md-2">قديمة: <?php echo $stats['old_records']; ?></span>
                            </small>
                            <?php if ($stats['old_records'] > 0): ?>
                                <button class="btn btn-sm btn-light" 
                                        onclick="cleanupLoginAttempts()" 
                                        title="حذف السجلات القديمة (أقدم من يوم واحد)">
                                    <i class="bi bi-trash"></i>
                                    <span class="d-none d-sm-inline">تنظيف</span>
                                </button>
                            <?php endif; ?>
                            <?php if ($stats['total'] > 0): ?>
                                <button class="btn btn-sm btn-danger" 
                                        onclick="deleteAllLoginAttempts()" 
                                        title="حذف جميع محاولات تسجيل الدخول">
                                    <i class="bi bi-trash-fill"></i>
                                    <span class="d-none d-sm-inline">حذف الكل</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle">
                                <thead>
                                    <tr>
                                        <th class="d-none d-md-table-cell">اسم المستخدم</th>
                                        <th>IP</th>
                                        <th>الحالة</th>
                                        <th class="d-none d-lg-table-cell">التاريخ</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($loginAttempts)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">لا توجد محاولات تسجيل دخول</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($loginAttempts as $attempt): ?>
                                            <tr id="attempt-row-<?php echo $attempt['id']; ?>">
                                                <td class="d-none d-md-table-cell">
                                                    <small><?php echo htmlspecialchars($attempt['username'] ?? '-'); ?></small>
                                                </td>
                                                <td><code><?php echo htmlspecialchars($attempt['ip_address']); ?></code></td>
                                                <td>
                                                    <?php if ($attempt['success']): ?>
                                                        <span class="badge bg-success">نجح</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">فشل</span>
                                                        <?php if (!empty($attempt['failure_reason'])): ?>
                                                            <small class="text-muted d-block d-md-none"><?php echo htmlspecialchars($attempt['failure_reason']); ?></small>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-lg-table-cell">
                                                    <small><?php echo formatDateTime($attempt['created_at']); ?></small>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-danger" 
                                                            onclick="deleteLoginAttempt(<?php echo $attempt['id']; ?>, event)"
                                                            title="حذف المحاولة">
                                                        <i class="bi bi-trash"></i>
                                                        <span class="d-none d-sm-inline">حذف</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="tab-pane fade <?php echo $activeTab === 'usage' ? 'show active' : ''; ?>" 
         id="usage-content" 
         role="tabpanel" 
         aria-labelledby="usage-tab">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-xl-7">
                        <form class="row g-3 align-items-end" method="get">
                            <input type="hidden" name="tab" value="usage">
                            <?php if ($selectedUsageUserId): ?>
                                <input type="hidden" name="usage_user_id" value="<?php echo intval($selectedUsageUserId); ?>">
                            <?php endif; ?>
                            <div class="col-12 col-md-6 col-xl-5">
                                <label class="form-label">التاريخ</label>
                                <input type="date" name="usage_date" value="<?php echo htmlspecialchars($usageDate); ?>" class="form-control">
                            </div>
                            <div class="col-12 col-md-4 col-xl-4">
                                <label class="form-label">عدد السجلات</label>
                                <input type="number" name="usage_limit" value="<?php echo htmlspecialchars($usageLimit); ?>" min="10" max="200" step="10" class="form-control">
                            </div>
                            <div class="col-12 col-md-2 col-xl-3">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-arrow-repeat me-2"></i>
                                    تحديث
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="bg-light border rounded p-3 h-100">
                            <div class="d-flex flex-column gap-2">
                                <div>
                                    <small class="text-muted d-block">
                                        حد المستخدم اليومي: 
                                        <strong><?php echo $usageThresholdUser ? number_format($usageThresholdUser) : 'غير محدد'; ?></strong>
                                    </small>
                                    <small class="text-muted d-block">
                                        حد عنوان IP اليومي: 
                                        <strong><?php echo $usageThresholdIp ? number_format($usageThresholdIp) : 'غير محدد'; ?></strong>
                                    </small>
                                    <small class="text-muted d-block">
                                        نافذة المراقبة: 
                                        <strong><?php echo defined('REQUEST_USAGE_ALERT_WINDOW_MINUTES') ? intval(REQUEST_USAGE_ALERT_WINDOW_MINUTES) : 1440; ?></strong> دقيقة
                                    </small>
                                </div>
                                <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
                                    <input type="hidden" name="action" value="cleanup_usage">
                                    <?php if ($selectedUsageUserId): ?>
                                        <input type="hidden" name="usage_user_id" value="<?php echo intval($selectedUsageUserId); ?>">
                                    <?php endif; ?>
                                    <input type="hidden" name="usage_date" value="<?php echo htmlspecialchars($usageDate); ?>">
                                    <label class="form-label mb-0 me-2">
                                        <small>
                                            <?php if ($selectedUsageUserId): ?>
                                                حذف سجلات المستخدم المحدد
                                            <?php else: ?>
                                                تنظيف الأقدم من
                                            <?php endif; ?>
                                        </small>
                                    </label>
                                    <?php if (!$selectedUsageUserId): ?>
                                        <select name="days" class="form-select form-select-sm w-auto">
                                            <option value="7">7 أيام</option>
                                            <option value="30" selected>30 يوماً</option>
                                            <option value="90">90 يوماً</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="days" value="30">
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('<?php echo $selectedUsageUserId ? 'سيتم حذف جميع سجلات الاستخدام الخاصة بالمستخدم المختار خلال التاريخ المعروض. هل تريد المتابعة؟' : 'سيتم حذف سجلات الاستخدام الأقدم من المدة المحددة. هل أنت متأكد؟'; ?>');">
                                        <i class="bi bi-trash me-1"></i>
                                        تنظيف السجلات
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-people-fill me-2"></i>استخدام حسب المستخدمين</h5>
                        <small class="text-white-50">عرض أعلى <?php echo htmlspecialchars($usageLimit); ?> مستخدم</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle">
                                <thead>
                                    <tr>
                                        <th>المستخدم</th>
                                        <th class="d-none d-md-table-cell">الدور</th>
                                        <th>عدد الطلبات</th>
                                        <th class="d-none d-lg-table-cell">آخر طلب</th>
                                        <th class="d-none d-xl-table-cell">عناوين IP</th>
                                        <th>إجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requestUsageUsers)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">لا توجد بيانات استخدام في هذا التاريخ</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($requestUsageUsers as $usage): ?>
                                            <?php
                                                $totalRequests = intval($usage['total_requests'] ?? 0);
                                                $isOverThreshold = $usageThresholdUser && $totalRequests >= $usageThresholdUser;
                                                $userId = intval($usage['user_id']);
                                                $detailUrlParams = [
                                                    'page' => 'security',
                                                    'tab' => 'usage',
                                                    'usage_date' => $usageDate,
                                                    'usage_limit' => $usageLimit,
                                                    'usage_user_id' => $userId,
                                                ];
                                                $detailUrl = '?' . http_build_query($detailUrlParams);
                                            ?>
                                            <tr<?php echo $selectedUsageUserId === $userId ? ' class="table-info"' : ''; ?>>
                                                <td>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($usage['username'] ?? 'غير معروف'); ?></div>
                                                    <?php if (!empty($usage['full_name'])): ?>
                                                        <small class="text-muted d-block"><?php echo htmlspecialchars($usage['full_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-md-table-cell">
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($usage['role'] ?? '-'); ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-semibold <?php echo $isOverThreshold ? 'text-danger' : ''; ?>">
                                                        <?php echo number_format($totalRequests); ?>
                                                    </span>
                                                    <?php if ($isOverThreshold): ?>
                                                        <span class="badge bg-danger ms-1">مرتفع</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-lg-table-cell">
                                                    <small><?php echo !empty($usage['last_request']) ? formatDateTime($usage['last_request']) : '-'; ?></small>
                                                </td>
                                                <td class="d-none d-xl-table-cell">
                                                    <small class="text-muted"><?php echo htmlspecialchars($usage['recent_ips'] ?? '-'); ?></small>
                                                </td>
                                                <td>
                                                    <a href="<?php echo htmlspecialchars($detailUrl); ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-list-check"></i>
                                                        <span class="d-none d-sm-inline">تفاصيل</span>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-hdd-network me-2"></i>استخدام حسب عنوان IP</h5>
                        <small class="text-white-50">أعلى العناوين خلال اليوم</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle">
                                <thead>
                                    <tr>
                                        <th>عنوان IP</th>
                                        <th>الطلبات</th>
                                        <th class="d-none d-lg-table-cell">آخر نشاط</th>
                                        <th>المستخدمون</th>
                                        <th>حظر</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requestUsageIps)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">لا توجد بيانات استخدام في هذا التاريخ</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($requestUsageIps as $ipUsage): ?>
                                            <?php
                                                $totalRequests = intval($ipUsage['total_requests'] ?? 0);
                                                $isOverThreshold = $usageThresholdIp && $totalRequests >= $usageThresholdIp;
                                            ?>
                                            <tr>
                                                <td><code><?php echo htmlspecialchars($ipUsage['ip_address']); ?></code></td>
                                                <td>
                                                    <span class="fw-semibold <?php echo $isOverThreshold ? 'text-danger' : ''; ?>">
                                                        <?php echo number_format($totalRequests); ?>
                                                    </span>
                                                    <?php if ($isOverThreshold): ?>
                                                        <span class="badge bg-danger ms-1">مرتفع</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($ipUsage['anonymous_requests'])): ?>
                                                        <div><small class="text-muted">مجهول: <?php echo number_format($ipUsage['anonymous_requests']); ?></small></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-none d-lg-table-cell">
                                                    <small><?php echo !empty($ipUsage['last_request']) ? formatDateTime($ipUsage['last_request']) : '-'; ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo htmlspecialchars($ipUsage['usernames'] ?? '-'); ?></small>
                                                </td>
                                                <td>
                                                    <form method="post" class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2">
                                                        <input type="hidden" name="action" value="block_ip">
                                                        <input type="hidden" name="ip_address" value="<?php echo htmlspecialchars($ipUsage['ip_address'], ENT_QUOTES); ?>">
                                                        <input type="hidden" name="reason" value="نشاط مرتفع بتاريخ <?php echo htmlspecialchars($usageDate); ?>">
                                                        <select name="duration_minutes" class="form-select form-select-sm w-auto" aria-label="مدة الحظر">
                                                            <option value="60">1 ساعة</option>
                                                            <option value="240">4 ساعات</option>
                                                            <option value="1440">يوم</option>
                                                            <option value="4320">3 أيام</option>
                                                            <option value="0">دائم</option>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('هل تريد حظر عنوان IP هذا؟');">
                                                            <i class="bi bi-shield-exclamation"></i>
                                                            <span class="d-none d-xl-inline">حظر</span>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-3 mt-1">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-warning text-white">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>تنبيهات الاستخدام المرتفع</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive dashboard-table-wrapper">
                            <table class="table dashboard-table dashboard-table--compact align-middle">
                                <thead>
                                    <tr>
                                        <th>النوع</th>
                                        <th>المعرف</th>
                                        <th>الطلبات</th>
                                        <th>الحد</th>
                                        <th class="d-none d-lg-table-cell">الفترة</th>
                                        <th>إجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requestUsageAlerts)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">لا توجد تنبيهات خلال هذا اليوم</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($requestUsageAlerts as $alert): ?>
                                            <?php
                                                $isUserAlert = ($alert['identifier_type'] === 'user');
                                                $identifierValue = $alert['identifier_value'];
                                                $count = intval($alert['request_count'] ?? 0);
                                                $threshold = intval($alert['threshold'] ?? 0);
                                                $windowLabel = formatDateTime($alert['window_start']) . ' → ' . formatDateTime($alert['window_end']);
                                                $actionHtml = '-';
                                                if ($isUserAlert) {
                                                    $alertUrl = '?' . http_build_query([
                                                        'page' => 'security',
                                                        'tab' => 'usage',
                                                        'usage_date' => $usageDate,
                                                        'usage_limit' => $usageLimit,
                                                        'usage_user_id' => intval($identifierValue),
                                                    ]);
                                                    $actionHtml = '<a href="' . htmlspecialchars($alertUrl) . '" class="btn btn-sm btn-outline-primary">تفاصيل المستخدم</a>';
                                                } else {
                                                    $actionHtml = '<form method="post" class="d-inline">
                                                        <input type="hidden" name="action" value="block_ip">
                                                        <input type="hidden" name="ip_address" value="' . htmlspecialchars($identifierValue, ENT_QUOTES) . '">
                                                        <input type="hidden" name="reason" value="تنبيه استخدام مرتفع ' . htmlspecialchars($usageDate) . '">
                                                        <input type="hidden" name="duration_minutes" value="1440">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'حظر عنوان IP المرتفع؟\');">
                                                            <i class="bi bi-shield-exclamation"></i>
                                                        </button>
                                                    </form>';
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <?php if ($isUserAlert): ?>
                                                        <span class="badge bg-primary">مستخدم</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">IP</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($isUserAlert): ?>
                                                        <span class="fw-semibold">#<?php echo htmlspecialchars($identifierValue); ?></span>
                                                    <?php else: ?>
                                                        <code><?php echo htmlspecialchars($identifierValue); ?></code>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="fw-semibold text-danger"><?php echo number_format($count); ?></span></td>
                                                <td><?php echo number_format($threshold); ?></td>
                                                <td class="d-none d-lg-table-cell"><small><?php echo htmlspecialchars($windowLabel); ?></small></td>
                                                <td><?php echo $actionHtml; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>تفاصيل المستخدم المختار</h5>
                        <?php if ($selectedUsageUserId): ?>
                            <small class="text-white-50">المعرف: <?php echo intval($selectedUsageUserId); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!$selectedUsageUserId || empty($selectedUsageUser)): ?>
                            <div class="alert alert-light border text-muted mb-0">
                                اختر مستخدماً من الجدول لرؤية تفاصيل الطلبات الخاصة به خلال اليوم.
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <h6 class="mb-1"><?php echo htmlspecialchars($selectedUsageUser['username'] ?? ''); ?></h6>
                                <?php if (!empty($selectedUsageUser['full_name'])): ?>
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($selectedUsageUser['full_name']); ?></small>
                                <?php endif; ?>
                                <small class="text-muted d-block">عدد الطلبات: <?php echo number_format(intval($selectedUsageUser['total_requests'] ?? 0)); ?></small>
                            </div>
                            <div class="table-responsive dashboard-table-wrapper">
                                <table class="table dashboard-table dashboard-table--compact align-middle">
                                    <thead>
                                        <tr>
                                            <th>المسار</th>
                                            <th class="d-none d-md-table-cell">الطريقة</th>
                                            <th>الطلبات</th>
                                            <th class="d-none d-lg-table-cell">آخر طلب</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($selectedUsageUserDetails)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted">لا توجد تفاصيل متاحة</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($selectedUsageUserDetails as $detail): ?>
                                                <tr>
                                                    <td><small><?php echo htmlspecialchars($detail['path'] ?? '-'); ?></small></td>
                                                    <td class="d-none d-md-table-cell"><span class="badge bg-light text-dark"><?php echo htmlspecialchars($detail['method'] ?? '-'); ?></span></td>
                                                    <td><?php echo number_format(intval($detail['total_requests'] ?? 0)); ?></td>
                                                    <td class="d-none d-lg-table-cell"><small><?php echo !empty($detail['last_request']) ? formatDateTime($detail['last_request']) : '-'; ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="tab-pane fade <?php echo $activeTab === 'audit' ? 'show active' : ''; ?>"
         id="audit-content"
         role="tabpanel"
         aria-labelledby="audit-tab">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <input type="hidden" name="page" value="security">
                    <input type="hidden" name="tab" value="audit">
                    <div class="col-12 col-md-3">
                        <label class="form-label">المستخدم</label>
                        <select name="audit_user" class="form-select">
                            <option value="">الكل</option>
                            <?php foreach ($auditUsersList as $auditUserOption): ?>
                                <option value="<?php echo intval($auditUserOption['id']); ?>"
                                    <?php echo ($auditUserFilter && intval($auditUserOption['id']) === intval($auditUserFilter)) ? 'selected' : ''; ?>>
                                    <?php
                                        $label = $auditUserOption['full_name'] ?: $auditUserOption['username'];
                                        echo htmlspecialchars($label . ' (' . $auditUserOption['username'] . ')');
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">الإجراء</label>
                        <select name="audit_action" class="form-select">
                            <option value="">الكل</option>
                            <?php foreach ($auditActionsList as $actionRow): ?>
                                <?php if (empty($actionRow['action'])) { continue; } ?>
                                <option value="<?php echo htmlspecialchars($actionRow['action']); ?>"
                                    <?php echo ($auditActionFilter && $auditActionFilter === $actionRow['action']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($actionRow['action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">النوع</label>
                        <select name="audit_entity" class="form-select">
                            <option value="">الكل</option>
                            <?php foreach ($auditEntitiesList as $entityRow): ?>
                                <?php if (empty($entityRow['entity_type'])) { continue; } ?>
                                <option value="<?php echo htmlspecialchars($entityRow['entity_type']); ?>"
                                    <?php echo ($auditEntityFilter && $auditEntityFilter === $entityRow['entity_type']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($entityRow['entity_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">عدد السجلات</label>
                        <select name="audit_limit" class="form-select">
                            <?php foreach ([10, 20, 30, 40, 50] as $limitOption): ?>
                                <option value="<?php echo $limitOption; ?>" <?php echo $auditPerPage === $limitOption ? 'selected' : ''; ?>>
                                    <?php echo $limitOption; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">من تاريخ</label>
                        <input type="date" name="audit_date_from" class="form-control" value="<?php echo htmlspecialchars($auditDateFrom ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">إلى تاريخ</label>
                        <input type="date" name="audit_date_to" class="form-control" value="<?php echo htmlspecialchars($auditDateTo ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-filter-circle me-2"></i>
                            تطبيق الفلاتر
                        </button>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label d-none d-md-block">&nbsp;</label>
                        <a href="?page=security&tab=audit" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-arrow-counterclockwise me-2"></i>
                            إعادة التعيين
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2">
                <div>
                    <h5 class="mb-0"><i class="bi bi-journal-check me-2"></i>سجل التدقيق</h5>
                    <small class="text-white-50 d-block">إجمالي السجلات: <?php echo number_format($auditTotal); ?></small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-light text-dark">
                        <i class="bi bi-list-ul me-1"></i>
                        صفحة <?php echo $auditPage; ?> من <?php echo $auditTotalPages; ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-table-wrapper">
                    <table class="table dashboard-table dashboard-table--compact align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>المستخدم</th>
                                <th class="d-none d-lg-table-cell">الدور</th>
                                <th>الإجراء</th>
                                <th class="d-none d-md-table-cell">النوع</th>
                                <th class="d-none d-xl-table-cell">المعرف</th>
                                <th class="d-none d-lg-table-cell">عنوان IP</th>
                                <th>التاريخ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($auditLogs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="bi bi-clipboard-x me-2"></i>لا توجد سجلات في الفترة المحددة
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($log['username'] ?? 'غير معروف'); ?></div>
                                            <?php if (!empty($log['user_id'])): ?>
                                                <small class="text-muted d-block">#<?php echo intval($log['user_id']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <?php if (!empty($log['role'])): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($log['role']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                            <?php if (!empty($log['new_value'])): ?>
                                                <small class="text-muted d-block d-xl-none mt-1">تغييرات متوفرة</small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <?php echo htmlspecialchars($log['entity_type'] ?? '-'); ?>
                                        </td>
                                        <td class="d-none d-xl-table-cell">
                                            <?php echo $log['entity_id'] !== null ? htmlspecialchars((string)$log['entity_id']) : '-'; ?>
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <?php echo !empty($log['ip_address']) ? '<code>' . htmlspecialchars($log['ip_address']) . '</code>' : '<span class="text-muted">-</span>'; ?>
                                        </td>
                                        <td>
                                            <small><?php echo formatDateTime($log['created_at']); ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($auditLogs)): ?>
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="alert alert-light border mb-0">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="bi bi-info-circle text-primary fs-4"></i>
                                    <div>
                                        <strong>تفاصيل إضافية</strong>
                                        <p class="mb-0 small text-muted">
                                            لمشاهدة التفاصيل الكاملة لأي سجل (القيمة القديمة والجديدة)، يرجى الرجوع إلى قاعدة البيانات أو تمكين عرض متقدم عند الحاجة.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($auditTotalPages > 1): ?>
                <div class="card-footer bg-light">
                    <nav aria-label="التنقل بين صفحات سجل التدقيق">
                        <ul class="pagination justify-content-center flex-wrap mb-0">
                            <?php
                                $prevQuery = $auditQueryBase;
                                $prevQuery['audit_page'] = max(1, $auditPage - 1);
                                $nextQuery = $auditQueryBase;
                                $nextQuery['audit_page'] = min($auditTotalPages, $auditPage + 1);
                            ?>
                            <li class="page-item <?php echo $auditPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars('?' . http_build_query($prevQuery)); ?>" aria-label="السابق">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>
                            <?php
                                $startPage = max(1, $auditPage - 2);
                                $endPage = min($auditTotalPages, $auditPage + 2);
                                if ($startPage > 1) {
                                    $firstQuery = $auditQueryBase;
                                    $firstQuery['audit_page'] = 1;
                                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars('?' . http_build_query($firstQuery)) . '">1</a></li>';
                                    if ($startPage > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                for ($i = $startPage; $i <= $endPage; $i++) {
                                    $pageQuery = $auditQueryBase;
                                    $pageQuery['audit_page'] = $i;
                                    echo '<li class="page-item ' . ($i === $auditPage ? 'active' : '') . '"><a class="page-link" href="' . htmlspecialchars('?' . http_build_query($pageQuery)) . '">' . $i . '</a></li>';
                                }
                                if ($endPage < $auditTotalPages) {
                                    if ($endPage < $auditTotalPages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    $lastQuery = $auditQueryBase;
                                    $lastQuery['audit_page'] = $auditTotalPages;
                                    echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars('?' . http_build_query($lastQuery)) . '">' . $auditTotalPages . '</a></li>';
                                }
                            ?>
                            <li class="page-item <?php echo $auditPage >= $auditTotalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars('?' . http_build_query($nextQuery)); ?>" aria-label="التالي">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="tab-pane fade <?php echo $activeTab === 'users' ? 'show active' : ''; ?>" 
         id="users-content" 
         role="tabpanel" 
         aria-labelledby="users-tab">
        <?php 
        $usersModuleContext = 'security';
        $usersModulePath = __DIR__ . '/users.php';
        if (file_exists($usersModulePath)) {
            include $usersModulePath;
        } else {
            echo '<div class="alert alert-warning">إدارة المستخدمين غير متاحة حالياً</div>';
        }
        unset($usersModuleContext);
        ?>
    </div>
    
    <!-- Tab: الصلاحيات -->
    <div class="tab-pane fade <?php echo $activeTab === 'permissions' ? 'show active' : ''; ?>" 
         id="permissions-content" 
         role="tabpanel" 
         aria-labelledby="permissions-tab">
        <div class="row">
            <div class="col-12 col-md-4 mb-3 mb-md-0">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-people me-2"></i>المستخدمون</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($users as $user): ?>
                                <a href="?page=security&tab=permissions&user_id=<?php echo $user['id']; ?>" 
                                   class="list-group-item list-group-item-action <?php echo $selectedUserId == $user['id'] ? 'active' : ''; ?>">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h6>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($user['username']); ?></small>
                                        </div>
                                        <span class="badge bg-<?php 
                                            echo $user['role'] === 'manager' ? 'danger' : 
                                                ($user['role'] === 'accountant' ? 'primary' : 
                                                ($user['role'] === 'sales' ? 'success' : 'info')); 
                                        ?> ms-2">
                                            <?php echo $lang['role_' . $user['role']] ?? $user['role']; ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-12 col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>صلاحيات المستخدم</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($selectedUserId): ?>
                            <?php
                            $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$selectedUserId]);
                            $userPermsMap = [];
                            foreach ($userPermissions as $up) {
                                $userPermsMap[$up['name']] = $up;
                            }
                            
                            // تجميع الصلاحيات حسب الفئة
                            $permissionsByCategory = [];
                            foreach ($permissions as $perm) {
                                $category = $perm['category'] ?? 'other';
                                if (!isset($permissionsByCategory[$category])) {
                                    $permissionsByCategory[$category] = [];
                                }
                                $permissionsByCategory[$category][] = $perm;
                            }
                            
                            if (empty($permissions)) {
                                echo '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد صلاحيات متاحة في النظام</div>';
                            }
                            ?>
                            
                            <h6 class="mb-3">الصلاحيات لـ: <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong></h6>
                            
                            <?php foreach ($permissionsByCategory as $category => $perms): ?>
                                <div class="mb-4">
                                    <h6 class="text-primary mb-2">
                                        <i class="bi bi-folder me-2"></i><?php echo htmlspecialchars(ucfirst($category)); ?>
                                    </h6>
                                    <div class="table-responsive dashboard-table-wrapper">
                                        <table class="table dashboard-table dashboard-table--compact align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="min-width: 120px;">الصلاحية</th>
                                                    <th class="d-none d-md-table-cell" style="min-width: 150px;">الوصف</th>
                                                    <th style="min-width: 100px;">الحالة</th>
                                                    <th style="min-width: 80px;">الإجراء</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($perms as $perm): ?>
                                                    <?php
                                                    $hasPermission = isset($userPermsMap[$perm['name']]);
                                                    $granted = $hasPermission && isset($userPermsMap[$perm['name']]['granted']) && $userPermsMap[$perm['name']]['granted'];
                                                    $source = $hasPermission ? $userPermsMap[$perm['name']]['source'] : 'none';
                                                    ?>
                                                    <tr class="<?php echo $granted ? 'table-success' : ''; ?>">
                                                        <td>
                                                            <strong class="d-block"><?php echo htmlspecialchars($perm['name']); ?></strong>
                                                            <small class="text-muted d-md-none"><?php echo htmlspecialchars($perm['description'] ?? '-'); ?></small>
                                                        </td>
                                                        <td class="d-none d-md-table-cell">
                                                            <?php echo htmlspecialchars($perm['description'] ?? '-'); ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($granted): ?>
                                                                <?php if ($source === 'role'): ?>
                                                                    <span class="badge bg-info">
                                                                        <i class="bi bi-shield-check"></i>
                                                                        <span class="d-none d-sm-inline">من الدور</span>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-success">
                                                                        <i class="bi bi-check-circle"></i>
                                                                        <span class="d-none d-sm-inline">مفعّل</span>
                                                                    </span>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">
                                                                    <i class="bi bi-x-circle"></i>
                                                                    <span class="d-none d-sm-inline">غير مفعّل</span>
                                                                </span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($source === 'role' && $granted): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="grant_permission">
                                                                    <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                                    <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                                    <button type="submit" 
                                                                            class="btn btn-sm btn-success w-100" 
                                                                            title="تفعيل الصلاحية كصلاحية مخصصة">
                                                                        <i class="bi bi-check-circle"></i>
                                                                        <span class="d-none d-lg-inline">تفعيل</span>
                                                                    </button>
                                                                </form>
                                                            <?php elseif ($granted): ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="revoke_permission">
                                                                    <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                                    <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                                    <button type="submit" 
                                                                            class="btn btn-sm btn-danger w-100" 
                                                                            onclick="return confirm('هل أنت متأكد من إلغاء هذه الصلاحية؟')"
                                                                            title="إلغاء الصلاحية">
                                                                        <i class="bi bi-x-circle"></i>
                                                                        <span class="d-none d-lg-inline">إلغاء</span>
                                                                    </button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="POST" style="display: inline;">
                                                                    <input type="hidden" name="action" value="grant_permission">
                                                                    <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                                    <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                                    <button type="submit" 
                                                                            class="btn btn-sm btn-success w-100"
                                                                            title="منح الصلاحية">
                                                                        <i class="bi bi-check-circle"></i>
                                                                        <span class="d-none d-lg-inline">منح</span>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-center py-4">
                                <i class="bi bi-info-circle me-2"></i>يرجى اختيار مستخدم لعرض صلاحياته
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tab: النسخ الاحتياطي -->
<div class="tab-pane fade <?php echo $activeTab === 'backup' ? 'show active' : ''; ?>" 
     id="backup-content"
     role="tabpanel"
     aria-labelledby="backup-tab">
    <?php 
    $backupModule = __DIR__ . '/backups.php';
    if (file_exists($backupModule)) {
        include $backupModule;
    } else {
        echo '<div class="alert alert-warning">صفحة النسخ الاحتياطي غير متاحة حالياً</div>';
    }
    ?>
</div>
</div>

<script>
function cleanupLoginAttempts() {
    if (!confirm('هل أنت متأكد من حذف جميع محاولات تسجيل الدخول القديمة (أقدم من يوم واحد)؟')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري التنظيف...';
    
    // حساب المسار الصحيح للـ API
    <?php
    require_once __DIR__ . '/../../includes/path_helper.php';
    $basePath = getBasePath();
    $apiPath = rtrim($basePath, '/') . '/api/cleanup_login_attempts.php';
    ?>
    const apiPath = '<?php echo $apiPath; ?>';
    
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: new URLSearchParams({
            action: 'cleanup',
            days: 1
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Expected JSON but got: ' + contentType);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('تم حذف ' + data.deleted + ' سجل بنجاح');
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
        alert('خطأ في الاتصال بالخادم: ' + error.message);
    });
}

// حذف جميع محاولات تسجيل الدخول
function deleteAllLoginAttempts() {
    if (!confirm('هل أنت متأكد من حذف جميع محاولات تسجيل الدخول؟\n\nهذا الإجراء لا يمكن التراجع عنه!')) {
        return;
    }
    
    // تأكيد إضافي
    if (!confirm('تحذير: سيتم حذف جميع محاولات تسجيل الدخول من قاعدة البيانات.\n\nهل أنت متأكد تماماً؟')) {
        return;
    }
    
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الحذف...';
    
    // حساب المسار الصحيح للـ API
    <?php
    $basePath = getBasePath();
    $apiPath = rtrim($basePath, '/') . '/api/cleanup_login_attempts.php';
    ?>
    const apiPath = '<?php echo $apiPath; ?>';
    
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: new URLSearchParams({
            action: 'delete_all'
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Expected JSON but got: ' + contentType);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('تم حذف ' + data.deleted + ' محاولة تسجيل دخول بنجاح');
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        btn.disabled = false;
        btn.innerHTML = originalHTML;
        alert('خطأ في الاتصال بالخادم: ' + error.message);
    });
}

function unblockIP(ip, evt) {
    if (!ip) {
        console.error('unblockIP: Missing IP address');
        alert('خطأ: عنوان IP غير موجود');
        return;
    }
    
    if (!confirm('هل أنت متأكد من إلغاء حظر العنوان: ' + ip + '؟')) {
        return;
    }
    
    let btn = null;
    let originalHTML = '';
    
    const e = evt || window.event || event;
    if (e && e.target) {
        btn = e.target.closest('button');
        originalHTML = btn ? btn.innerHTML : '';
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري المعالجة...';
    }
    
    <?php
    $basePath = getBasePath();
    $apiPath = rtrim($basePath, '/') . '/api/unblock_ip.php';
    ?>
    const apiPath = '<?php echo $apiPath; ?>';
    
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: new URLSearchParams({
            ip: ip
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Expected JSON but got: ' + contentType);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('تم إلغاء حظر العنوان بنجاح');
            location.reload();
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم: ' + error.message);
    });
}

// حفظ التاب النشط عند التبديل
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('#securityTabs button[data-bs-toggle="tab"]');
    tabs.forEach(tab => {
        tab.addEventListener('shown.bs.tab', function (e) {
            const activeTab = e.target.getAttribute('data-bs-target').replace('#', '');
            // تحديث URL بدون إعادة تحميل الصفحة
            const url = new URL(window.location);
            url.searchParams.set('tab', activeTab.replace('-content', ''));
            window.history.pushState({}, '', url);
        });
    });
});

// حذف محاولة تسجيل دخول واحدة
function deleteLoginAttempt(attemptId, evt) {
    if (!attemptId || attemptId <= 0) {
        alert('خطأ: معرّف المحاولة غير صحيح');
        return;
    }
    
    if (!confirm('هل أنت متأكد من حذف هذه المحاولة؟')) {
        return;
    }
    
    let btn = null;
    let originalHTML = '';
    
    const e = evt || window.event || event;
    if (e && e.target) {
        btn = e.target.closest('button');
        originalHTML = btn ? btn.innerHTML : '';
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    }
    
    // حساب المسار الصحيح للـ API
    <?php
    require_once __DIR__ . '/../../includes/path_helper.php';
    $basePath = getBasePath();
    $apiPath = rtrim($basePath, '/') . '/api/delete_login_attempt.php';
    ?>
    const apiPath = '<?php echo $apiPath; ?>';
    
    fetch(apiPath, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: new URLSearchParams({
            id: attemptId
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error('Expected JSON but got: ' + contentType);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // إزالة الصف من الجدول
            const row = document.getElementById('attempt-row-' + attemptId);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                    
                    // التحقق من وجود صفوف أخرى
                    const tbody = row.closest('tbody');
                    if (tbody && tbody.children.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">لا توجد محاولات تسجيل دخول</td></tr>';
                    }
                    
                    // تحديث الإحصائيات إذا كانت متاحة
                    if (data.stats) {
                        location.reload(); // إعادة تحميل الصفحة لتحديث الإحصائيات
                    }
                }, 300);
            } else {
                location.reload();
            }
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.message || 'حدث خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم: ' + error.message);
    });
}
</script>

