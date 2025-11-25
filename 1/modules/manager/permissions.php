<?php
/**
 * صفحة إدارة الصلاحيات للمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/table_styles.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة العمليات
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

// الحصول على البيانات
$users = $db->query("SELECT id, username, full_name, role FROM users WHERE status = 'active' ORDER BY role, username");
$permissions = getAllPermissions();
$selectedUserId = $_GET['user_id'] ?? ($users[0]['id'] ?? null);
$userPermissions = $selectedUserId ? getUserPermissions($selectedUserId) : [];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-shield-check me-2"></i>إدارة الصلاحيات</h2>
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

<div class="row">
    <div class="col-12 col-md-4 mb-3 mb-md-0">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>المستخدمون</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($users as $user): ?>
                        <a href="?page=permissions&user_id=<?php echo $user['id']; ?>" 
                           class="list-group-item list-group-item-action <?php echo $selectedUserId == $user['id'] ? 'active' : ''; ?>">
                            <div class="d-flex w-100 justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></h6>
                                    <small class="text-muted"><?php echo htmlspecialchars($user['username']); ?></small>
                                </div>
                                <span class="badge bg-<?php 
                                    echo $user['role'] === 'manager' ? 'danger' : 
                                        ($user['role'] === 'accountant' ? 'primary' : 
                                        ($user['role'] === 'sales' ? 'success' : 'info')); 
                                ?>">
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
                <h5 class="mb-0">صلاحيات المستخدم</h5>
            </div>
            <div class="card-body">
                <?php if ($selectedUserId): ?>
                    <?php
                    $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$selectedUserId]);
                    $userPermsMap = [];
                    foreach ($userPermissions as $up) {
                        $userPermsMap[$up['name']] = $up;
                    }
                    
                    // تجميع الصلاحيات حسب الفئة - عرض جميع الصلاحيات
                    $permissionsByCategory = [];
                    foreach ($permissions as $perm) {
                        $category = $perm['category'] ?? 'other';
                        if (!isset($permissionsByCategory[$category])) {
                            $permissionsByCategory[$category] = [];
                        }
                        $permissionsByCategory[$category][] = $perm;
                    }
                    
                    // إذا لم تكن هناك صلاحيات، عرض رسالة
                    if (empty($permissions)) {
                        echo '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>لا توجد صلاحيات متاحة في النظام</div>';
                    }
                    ?>
                    
                    <h6>الصلاحيات لـ: <strong><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></strong></h6>
                    <hr>
                    
                    <?php foreach ($permissionsByCategory as $category => $perms): ?>
                        <div class="mb-4">
                            <h6 class="text-primary"><?php echo htmlspecialchars(ucfirst($category)); ?></h6>
                            <div class="table-responsive dashboard-table-wrapper">
                                <table class="table dashboard-table dashboard-table--compact align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width: 150px;">الصلاحية</th>
                                            <th style="min-width: 200px;">الوصف</th>
                                            <th style="min-width: 120px;">الحالة</th>
                                            <th style="min-width: 100px;">الإجراء</th>
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
                                                <td><strong><?php echo htmlspecialchars($perm['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($perm['description'] ?? '-'); ?></td>
                                                <td>
                                                    <?php if ($granted): ?>
                                                        <?php if ($source === 'role'): ?>
                                                            <span class="badge bg-info"><i class="bi bi-shield-check"></i> من الدور</span>
                                                        <?php elseif ($source === 'user'): ?>
                                                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> مفعّل</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> مفعّل</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary"><i class="bi bi-x-circle"></i> غير مفعّل</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column gap-1">
                                                        <?php if ($source === 'role' && $granted): ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="grant_permission">
                                                                <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                                <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-success w-100" title="تفعيل الصلاحية كصلاحية مخصصة">
                                                                    <i class="bi bi-check-circle"></i> <span class="d-none d-md-inline">تفعيل</span>
                                                                </button>
                                                            </form>
                                                            <small class="text-muted text-center d-block d-md-none">(من الدور)</small>
                                                        <?php elseif ($granted): ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="revoke_permission">
                                                                <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                                <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-danger w-100" onclick="return confirm('هل أنت متأكد من إلغاء هذه الصلاحية؟')">
                                                                    <i class="bi bi-x-circle"></i> <span class="d-none d-md-inline">إلغاء</span>
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="grant_permission">
                                                                <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                                <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-success w-100">
                                                                    <i class="bi bi-check-circle"></i> <span class="d-none d-md-inline">منح</span>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted">يرجى اختيار مستخدم لعرض صلاحياته</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

