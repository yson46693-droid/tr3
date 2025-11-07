<?php
/**
 * صفحة إدارة النسخ الاحتياطية للمدير
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/backup.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_backup') {
        $backupType = $_POST['backup_type'] ?? 'manual';
        
        $result = createDatabaseBackup($backupType, $currentUser['id']);
        
        if ($result['success']) {
            logAudit($currentUser['id'], 'create_backup', 'backup', null, null, [
                'type' => $backupType,
                'filename' => $result['filename']
            ]);
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// الحصول على الإحصائيات
$stats = getBackupStats();

// الحصول على قائمة النسخ مع Pagination
$allBackups = getBackups(1000); // الحصول على جميع النسخ للعدد الإجمالي
$totalBackups = count($allBackups);
$totalPages = ceil($totalBackups / $perPage);
$backups = array_slice($allBackups, $offset, $perPage);
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-database me-2"></i>النسخ الاحتياطية</h2>
    <button class="btn btn-primary" onclick="createBackup(event)">
        <i class="bi bi-plus-circle me-2"></i>إنشاء نسخة احتياطية
    </button>
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

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-database"></i></div>
            <div class="card-title">إجمالي النسخ</div>
            <div class="card-value"><?php echo $stats['total']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-check-circle"></i></div>
            <div class="card-title">نجحت</div>
            <div class="card-value"><?php echo $stats['success']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-x-circle"></i></div>
            <div class="card-title">فشلت</div>
            <div class="card-value"><?php echo $stats['failed']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="dashboard-card">
            <div class="card-icon"><i class="bi bi-hdd"></i></div>
            <div class="card-title">الحجم الإجمالي</div>
            <div class="card-value"><?php echo formatFileSize($stats['total_size']); ?></div>
        </div>
    </div>
</div>

<!-- قائمة النسخ الاحتياطية -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>قائمة النسخ الاحتياطية</h5>
    </div>
    <div class="card-body">
        <?php if (empty($backups)): ?>
            <div class="text-center text-muted py-4">لا توجد نسخ احتياطية</div>
        <?php else: ?>
            <!-- عرض الجدول على الشاشات الكبيرة -->
            <div class="table-responsive d-none d-md-block">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>اسم الملف</th>
                            <th>النوع</th>
                            <th>الحجم</th>
                            <th>الحالة</th>
                            <th>أنشئ بواسطة</th>
                            <th>التاريخ</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td>
                                    <i class="bi bi-file-earmark-zip me-2"></i>
                                    <?php echo htmlspecialchars($backup['filename']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php 
                                        $types = [
                                            'daily' => 'يومي',
                                            'weekly' => 'أسبوعي',
                                            'monthly' => 'شهري',
                                            'manual' => 'يدوي'
                                        ];
                                        echo $types[$backup['backup_type']] ?? $backup['backup_type'];
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo formatFileSize($backup['file_size']); ?></td>
                                <td>
                                    <?php 
                                    $isSuccess = in_array($backup['status'], ['success', 'completed']);
                                    $statusText = $isSuccess ? 'نجح' : 'فشل';
                                    $statusClass = $isSuccess ? 'success' : 'danger';
                                    ?>
                                    <span class="badge bg-<?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                    <?php if (!$isSuccess && !empty($backup['error_message'])): ?>
                                        <br><small class="text-danger" title="<?php echo htmlspecialchars($backup['error_message']); ?>">
                                            <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars(mb_substr($backup['error_message'], 0, 50)); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($backup['created_by_name'] ?? 'نظام'); ?>
                                </td>
                                <td><?php echo formatDateTime($backup['created_at']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if (in_array($backup['status'], ['success', 'completed'])): ?>
                                            <?php 
                                            $downloadPath = str_replace('\\', '/', str_replace(BASE_PATH, '', $backup['file_path'])); 
                                            $downloadPath = '/' . ltrim($downloadPath, '/');
                                            ?>
                                            <a href="<?php echo htmlspecialchars($downloadPath); ?>" 
                                               class="btn btn-success" download>
                                                <i class="bi bi-download"></i> تحميل
                                            </a>
                                            <button class="btn btn-warning" 
                                                    onclick="restoreBackup(<?php echo $backup['id']; ?>, event)">
                                                <i class="bi bi-arrow-counterclockwise"></i> استعادة
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-danger" 
                                                onclick="deleteBackup(<?php echo $backup['id']; ?>, event)">
                                            <i class="bi bi-trash"></i> حذف
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- عرض Cards على الموبايل -->
            <div class="d-md-none">
                <?php foreach ($backups as $backup): ?>
                    <?php 
                    $types = [
                        'daily' => 'يومي',
                        'weekly' => 'أسبوعي',
                        'monthly' => 'شهري',
                        'manual' => 'يدوي'
                    ];
                    $isSuccess = in_array($backup['status'], ['success', 'completed']);
                    $statusText = $isSuccess ? 'نجح' : 'فشل';
                    $statusClass = $isSuccess ? 'success' : 'danger';
                    ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">
                                        <i class="bi bi-file-earmark-zip me-2"></i>
                                        <?php echo htmlspecialchars($backup['filename']); ?>
                                    </h6>
                                </div>
                                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">النوع:</small>
                                    <span class="badge bg-info">
                                        <?php echo $types[$backup['backup_type']] ?? $backup['backup_type']; ?>
                                    </span>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">الحجم:</small>
                                    <strong><?php echo formatFileSize($backup['file_size']); ?></strong>
                                </div>
                            </div>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <small class="text-muted d-block">أنشئ بواسطة:</small>
                                    <strong><?php echo htmlspecialchars($backup['created_by_name'] ?? 'نظام'); ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">التاريخ:</small>
                                    <strong><?php echo formatDateTime($backup['created_at']); ?></strong>
                                </div>
                            </div>
                            
                            <?php if (!$isSuccess && !empty($backup['error_message'])): ?>
                                <div class="alert alert-danger py-2 mb-2">
                                    <small>
                                        <i class="bi bi-exclamation-triangle"></i> 
                                        <?php echo htmlspecialchars(mb_substr($backup['error_message'], 0, 100)); ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2 d-flex mt-3">
                                <?php if (in_array($backup['status'], ['success', 'completed'])): ?>
                                    <?php 
                                    $downloadPath = str_replace('\\', '/', str_replace(BASE_PATH, '', $backup['file_path'])); 
                                    $downloadPath = '/' . ltrim($downloadPath, '/');
                                    ?>
                                    <a href="<?php echo htmlspecialchars($downloadPath); ?>" 
                                       class="btn btn-sm btn-success flex-fill" download>
                                        <i class="bi bi-download"></i> تحميل
                                    </a>
                                    <button class="btn btn-sm btn-warning flex-fill" 
                                            onclick="restoreBackup(<?php echo $backup['id']; ?>, event)">
                                        <i class="bi bi-arrow-counterclockwise"></i> استعادة
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-danger flex-fill" 
                                        onclick="deleteBackup(<?php echo $backup['id']; ?>, event)">
                                    <i class="bi bi-trash"></i> حذف
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=1">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=<?php echo $totalPages; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<script>
async function createBackup(evt) {
    if (!confirm('هل تريد إنشاء نسخة احتياطية الآن؟')) {
        return;
    }
    
    let btn = null;
    let originalHTML = '';
    
    // محاولة الحصول على الزر من event parameter
    const e = evt || window.event || (typeof event !== 'undefined' ? event : null);
    if (e && e.target) {
        btn = e.target.closest('button');
        originalHTML = btn ? btn.innerHTML : '';
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الإنشاء...';
    }
    
    try {
        // الحصول على المسار الصحيح لـ API
        let apiPath = '/api/backup.php';
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
        
        // إذا كنا في مجلد فرعي (مثل /dashboard/manager.php)
        if (pathParts.length > 0) {
            const basePath = '/' + pathParts[0];
            apiPath = basePath + '/api/backup.php';
        }
        
        console.log('Creating backup via:', apiPath);
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'create_backup',
                backup_type: 'manual'
            })
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تم الإنشاء';
            }
            // إظهار رسالة نجاح
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show mb-4';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle-fill me-2"></i>
                ${data.message || 'تم إنشاء النسخة الاحتياطية بنجاح'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            const container = document.querySelector('.card.shadow-sm') || document.querySelector('.row.mb-4');
            if (container) {
                container.parentNode.insertBefore(alertDiv, container);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            
            // إظهار رسالة خطأ مفصلة
            const errorMessage = data.error || data.message || 'حدث خطأ غير معروف';
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show mb-4';
            alertDiv.innerHTML = `
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>فشل إنشاء النسخة الاحتياطية:</strong><br>
                ${errorMessage}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            const container = document.querySelector('.card.shadow-sm') || document.querySelector('.row.mb-4');
            if (container) {
                container.parentNode.insertBefore(alertDiv, container);
            } else {
                document.body.insertBefore(alertDiv, document.body.firstChild);
            }
            
            console.error('Backup creation error:', data);
        }
    } catch (error) {
        console.error('Error creating backup:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    }
}

async function restoreBackup(backupId, evt) {
    if (!backupId) {
        console.error('restoreBackup: Missing backup ID');
        alert('خطأ: معرّف النسخة الاحتياطية غير موجود');
        return;
    }
    
    if (!confirm('تحذير: سيتم استبدال جميع البيانات بالنسخة الاحتياطية. هل أنت متأكد؟')) {
        return;
    }
    
    let btn = null;
    let originalHTML = '';
    
    const e = evt || window.event || (typeof event !== 'undefined' ? event : null);
    if (e && e.target) {
        btn = e.target.closest('button');
        originalHTML = btn ? btn.innerHTML : '';
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الاستعادة...';
    }
    
    try {
        // الحصول على المسار الصحيح لـ API
        let apiPath = '/api/backup.php';
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
        
        // إذا كنا في مجلد فرعي (مثل /dashboard/manager.php)
        if (pathParts.length > 0) {
            const basePath = '/' + pathParts[0];
            apiPath = basePath + '/api/backup.php';
        }
        
        console.log('Restoring backup via:', apiPath, 'ID:', backupId);
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'restore',
                backup_id: backupId
            })
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تمت الاستعادة';
            }
            alert('تم استعادة قاعدة البيانات بنجاح');
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.error || data.message || 'حدث خطأ غير معروف'));
        }
    } catch (error) {
        console.error('Error restoring backup:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    }
}

async function deleteBackup(backupId, evt) {
    if (!backupId) {
        console.error('deleteBackup: Missing backup ID');
        alert('خطأ: معرّف النسخة الاحتياطية غير موجود');
        return;
    }
    
    if (!confirm('هل أنت متأكد من حذف هذه النسخة الاحتياطية؟')) {
        return;
    }
    
    let btn = null;
    let originalHTML = '';
    
    const e = evt || window.event || (typeof event !== 'undefined' ? event : null);
    if (e && e.target) {
        btn = e.target.closest('button');
        originalHTML = btn ? btn.innerHTML : '';
    }
    
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الحذف...';
    }
    
    try {
        // الحصول على المسار الصحيح لـ API
        let apiPath = '/api/backup.php';
        const currentPath = window.location.pathname;
        const pathParts = currentPath.split('/').filter(p => p && p !== 'dashboard' && p !== 'modules' && !p.endsWith('.php'));
        
        // إذا كنا في مجلد فرعي (مثل /dashboard/manager.php)
        if (pathParts.length > 0) {
            const basePath = '/' + pathParts[0];
            apiPath = basePath + '/api/backup.php';
        }
        
        console.log('Deleting backup via:', apiPath, 'ID:', backupId);
        
        const response = await fetch(apiPath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                backup_id: backupId
            })
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const data = await response.json();
        
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="bi bi-check-circle me-2"></i>تم الحذف';
            }
            // إظهار رسالة نجاح
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show mb-4';
            alertDiv.innerHTML = `
                <i class="bi bi-check-circle-fill me-2"></i>
                ${data.message || 'تم حذف النسخة الاحتياطية بنجاح'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            const container = document.querySelector('.card.shadow-sm');
            if (container) {
                container.parentNode.insertBefore(alertDiv, container);
                setTimeout(() => {
                    alertDiv.remove();
                }, 5000);
            }
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            alert('خطأ: ' + (data.error || data.message || 'حدث خطأ غير معروف'));
        }
    } catch (error) {
        console.error('Error deleting backup:', error);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
        alert('خطأ في الاتصال بالخادم. يرجى المحاولة مرة أخرى.');
    }
}
</script>

