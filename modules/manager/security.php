<?php
/**
 * صفحة الأمان والصلاحيات للمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/security.php';

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

// الحصول على بيانات الأمان
$blockedIPs = getBlockedIPs();
$stats = getLoginAttemptsStats();
$loginAttempts = getLoginAttempts([], 20);

// تحديد التاب الافتراضي
$activeTab = $_GET['tab'] ?? 'security';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shield-lock me-2"></i>الأمان والصلاحيات</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Bootstrap Tabs -->
<style>
/* تحسين التصميم للهاتف */
@media (max-width: 768px) {
    #securityTabs {
        flex-direction: column;
    }
    
    #securityTabs .nav-item {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    #securityTabs .nav-link {
        width: 100%;
        text-align: center;
        padding: 0.75rem;
        font-size: 0.95rem;
    }
    
    .tab-content .card {
        margin-bottom: 1rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th, .table td {
        padding: 0.5rem;
    }
}
</style>

<ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto" id="securityTabs" role="tablist">
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
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
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
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
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
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle">
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

