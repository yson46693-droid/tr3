<?php
/**
 * صفحة مواصفات المنتجات (الوصفات المرجعية) للمدير
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

$redirectUrl = getRelativeUrl('manager.php?page=product_specifications');
$sessionErrorKey = 'manager_product_specs_error';
$sessionSuccessKey = 'manager_product_specs_success';
$error = '';
$success = '';

$formTokenKey = 'manager_product_specs_form_token';
$lastSubmissionKey = 'manager_product_specs_last_submission';
$generateFormToken = static function () {
    try {
        return bin2hex(random_bytes(32));
    } catch (Throwable $tokenError) {
        return hash('sha256', uniqid('', true) . microtime(true));
    }
};
$refreshFormToken = static function () use ($formTokenKey, $generateFormToken) {
    $newToken = $generateFormToken();
    $_SESSION[$formTokenKey] = $newToken;
    return $newToken;
};

if (empty($_SESSION[$formTokenKey]) || !is_string($_SESSION[$formTokenKey])) {
    $_SESSION[$formTokenKey] = $generateFormToken();
}
$formToken = $_SESSION[$formTokenKey];

if (!empty($_SESSION[$sessionErrorKey])) {
    $error = $_SESSION[$sessionErrorKey];
    unset($_SESSION[$sessionErrorKey]);
}

if (!empty($_SESSION[$sessionSuccessKey])) {
    $success = $_SESSION[$sessionSuccessKey];
    unset($_SESSION[$sessionSuccessKey]);
}

try {
    $specificationsTableCheck = $db->queryOne("SHOW TABLES LIKE 'product_specifications'");
    if (empty($specificationsTableCheck)) {
        $db->execute("\n            CREATE TABLE IF NOT EXISTS `product_specifications` (\n              `id` int(11) NOT NULL AUTO_INCREMENT,\n              `product_name` varchar(255) NOT NULL,\n              `raw_materials` longtext DEFAULT NULL,\n              `packaging` longtext DEFAULT NULL,\n              `notes` longtext DEFAULT NULL,\n              `created_by` int(11) DEFAULT NULL,\n              `updated_by` int(11) DEFAULT NULL,\n              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,\n              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,\n              PRIMARY KEY (`id`),\n              KEY `created_by` (`created_by`),\n              KEY `updated_by` (`updated_by`),\n              CONSTRAINT `product_specifications_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,\n              CONSTRAINT `product_specifications_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n        ");
    }
} catch (Throwable $tableError) {
    error_log('manager product specs: failed ensuring table -> ' . $tableError->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['form_token']) ? (string) $_POST['form_token'] : '';
    if ($submittedToken === '' || !is_string($formToken) || !hash_equals($formToken, $submittedToken)) {
        $_SESSION[$sessionErrorKey] = 'انتهت صلاحية النموذج، يرجى إعادة المحاولة.';
        $refreshFormToken();
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $productName = trim($_POST['product_name'] ?? '');
    $rawMaterials = trim($_POST['raw_materials'] ?? '');
    $packaging = trim($_POST['packaging'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $formPayload = [
        'action' => $action,
        'product_name' => $productName,
        'raw_materials' => $rawMaterials,
        'packaging' => $packaging,
        'notes' => $notes,
    ];
    $specId = 0;

    if ($action === 'edit_specification') {
        $specId = intval($_POST['spec_id'] ?? 0);
        $formPayload['spec_id'] = $specId;
    }

    $submissionFingerprint = hash('sha256', json_encode($formPayload, JSON_UNESCAPED_UNICODE));
    $duplicateWindowSeconds = 120;
    $lastSubmission = isset($_SESSION[$lastSubmissionKey]) && is_array($_SESSION[$lastSubmissionKey])
        ? $_SESSION[$lastSubmissionKey]
        : null;

    if (
        $lastSubmission
        && isset($lastSubmission['hash'], $lastSubmission['time'])
        && hash_equals($lastSubmission['hash'], $submissionFingerprint)
        && (int) $lastSubmission['time'] >= (time() - $duplicateWindowSeconds)
    ) {
        $_SESSION[$sessionSuccessKey] = 'تم التعامل مع هذا الطلب بالفعل. تم تجاهل التكرار.';
        $refreshFormToken();
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'add_specification') {
        if ($productName === '') {
            $_SESSION[$sessionErrorKey] = 'يجب إدخال اسم المنتج للمواصفة الجديدة.';
        } else {
            try {
                $db->execute(
                    "INSERT INTO product_specifications (product_name, raw_materials, packaging, notes, created_by)\n                     VALUES (?, ?, ?, ?, ?)",
                    [
                        $productName,
                        $rawMaterials !== '' ? $rawMaterials : null,
                        $packaging !== '' ? $packaging : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                    ]
                );
                $_SESSION[$sessionSuccessKey] = 'تم إضافة مواصفة المنتج بنجاح.';
                $_SESSION[$lastSubmissionKey] = [
                    'hash' => $submissionFingerprint,
                    'time' => time(),
                ];
            } catch (Throwable $insertError) {
                error_log('manager product specs: add error -> ' . $insertError->getMessage());
                $_SESSION[$sessionErrorKey] = 'تعذر إضافة المواصفة. يرجى المحاولة لاحقاً.';
            }
        }

        $refreshFormToken();
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($action === 'edit_specification') {
        if ($specId <= 0 || $productName === '') {
            $_SESSION[$sessionErrorKey] = 'بيانات المواصفة غير مكتملة.';
            $refreshFormToken();
            header('Location: ' . $redirectUrl);
            exit;
        }

        try {
            $specExists = $db->queryOne("SELECT id FROM product_specifications WHERE id = ?", [$specId]);
            if (!$specExists) {
                $_SESSION[$sessionErrorKey] = 'المواصفة المحددة غير موجودة.';
            } else {
                $db->execute(
                    "UPDATE product_specifications\n                     SET product_name = ?, raw_materials = ?, packaging = ?, notes = ?, updated_by = ?, updated_at = NOW()\n                     WHERE id = ?",
                    [
                        $productName,
                        $rawMaterials !== '' ? $rawMaterials : null,
                        $packaging !== '' ? $packaging : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                        $specId,
                    ]
                );
                $_SESSION[$sessionSuccessKey] = 'تم تحديث مواصفة المنتج بنجاح.';
                $_SESSION[$lastSubmissionKey] = [
                    'hash' => $submissionFingerprint,
                    'time' => time(),
                ];
            }
        } catch (Throwable $updateError) {
            error_log('manager product specs: update error -> ' . $updateError->getMessage());
            $_SESSION[$sessionErrorKey] = 'تعذر تحديث المواصفة. يرجى المحاولة لاحقاً.';
        }

        $refreshFormToken();
        header('Location: ' . $redirectUrl);
        exit;
    }

    $_SESSION[$sessionErrorKey] = 'طلب غير معروف.';
    $refreshFormToken();
    header('Location: ' . $redirectUrl);
    exit;
}

$formToken = $_SESSION[$formTokenKey] ?? $refreshFormToken();

require_once __DIR__ . '/../../includes/table_styles.php';

$productSpecifications = [];
try {
    $specSql = <<<SQL
    SELECT ps.*, 
           creator.full_name AS creator_name,
           updater.full_name AS updater_name
    FROM product_specifications ps
    LEFT JOIN users creator ON ps.created_by = creator.id
    LEFT JOIN users updater ON ps.updated_by = updater.id
    ORDER BY ps.created_at DESC
    SQL;

    $productSpecifications = $db->query($specSql);
} catch (Throwable $queryError) {
    error_log('manager product specs: query error -> ' . $queryError->getMessage());
    $productSpecifications = [];
}

$specificationsCount = is_countable($productSpecifications) ? count($productSpecifications) : 0;
?>

<div class="page-header mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h2 class="mb-1"><i class="bi bi-journal-text me-2"></i>مواصفات المنتجات</h2>
        <p class="text-muted mb-0">إدارة الوصفات المرجعية المعتمدة لخطوط الإنتاج.</p>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="badge bg-secondary-subtle text-dark px-3 py-2">
            <i class="bi bi-collection me-1"></i><?php echo number_format($specificationsCount); ?> مواصفة
        </span>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addSpecificationModal">
            <i class="bi bi-plus-circle me-1"></i>إضافة مواصفة جديدة
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>الوصفات المرجعية</h5>
        <button class="btn btn-light btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#addSpecificationModal">
            <i class="bi bi-plus-circle me-1"></i>إضافة مواصفة
        </button>
    </div>
    <div class="card-body">
        <?php if ($specificationsCount === 0): ?>
            <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                لم يتم تسجيل مواصفات بعد. استخدم زر <strong>إضافة مواصفة</strong> لتسجيل أول وصفة مرجعية.
            </div>
        <?php else: ?>
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>اسم المنتج</th>
                            <th width="24%">المواد الخام</th>
                            <th width="24%">أدوات التعبئة</th>
                            <th width="22%">ملاحظات الإنشاء</th>
                            <th>آخر تحديث</th>
                            <th>إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productSpecifications as $specification): ?>
                            <tr>
                                <td data-label="اسم المنتج">
                                    <strong><?php echo htmlspecialchars($specification['product_name']); ?></strong>
                                </td>
                                <td data-label="المواد الخام">
                                    <div class="text-muted small">الأوزان / الكميات</div>
                                    <div class="mt-1" style="white-space: pre-line;">
                                        <?php echo $specification['raw_materials'] !== null && $specification['raw_materials'] !== '' ? nl2br(htmlspecialchars($specification['raw_materials'])) : '<span class="text-muted">—</span>'; ?>
                                    </div>
                                </td>
                                <td data-label="أدوات التعبئة">
                                    <div class="text-muted small">الأعداد / الأحجام</div>
                                    <div class="mt-1" style="white-space: pre-line;">
                                        <?php echo $specification['packaging'] !== null && $specification['packaging'] !== '' ? nl2br(htmlspecialchars($specification['packaging'])) : '<span class="text-muted">—</span>'; ?>
                                    </div>
                                </td>
                                <td data-label="ملاحظات الإنشاء" style="white-space: pre-line;">
                                    <?php echo $specification['notes'] !== null && $specification['notes'] !== '' ? nl2br(htmlspecialchars($specification['notes'])) : '<span class="text-muted">—</span>'; ?>
                                </td>
                                <td data-label="آخر تحديث">
                                    <?php
                                        $updatedAt = $specification['updated_at'] ?? null;
                                        $createdAt = $specification['created_at'] ?? null;
                                        echo $updatedAt
                                            ? htmlspecialchars(formatDateTime($updatedAt))
                                            : ($createdAt ? htmlspecialchars(formatDateTime($createdAt)) : '—');
                                    ?>
                                    <div class="text-muted small mt-1">
                                        <?php if (!empty($specification['updated_by'])): ?>
                                            بواسطة <?php echo htmlspecialchars($specification['updater_name'] ?? 'غير محدد'); ?>
                                        <?php elseif (!empty($specification['created_by'])): ?>
                                            مسجل بواسطة <?php echo htmlspecialchars($specification['creator_name'] ?? 'غير محدد'); ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="إجراءات">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editSpecificationModal"
                                        data-id="<?php echo (int) $specification['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($specification['product_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                        data-raw="<?php echo htmlspecialchars($specification['raw_materials'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-packaging="<?php echo htmlspecialchars($specification['packaging'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                        data-notes="<?php echo htmlspecialchars($specification['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <i class="bi bi-pencil-square me-1"></i>تعديل
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addSpecificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST">
            <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($formToken); ?>">
            <input type="hidden" name="action" value="add_specification">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة مواصفة منتج جديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" name="product_name" class="form-control" required maxlength="255" placeholder="مثال: عسل باللوز 500 جم">
                </div>
                <div class="mb-3">
                    <label class="form-label">المواد الخام والأوزان</label>
                    <textarea name="raw_materials" rows="4" class="form-control" placeholder="مثال:
- عسل نقي: 450 جرام
- لوز مجروش: 50 جرام"></textarea>
                    <small class="text-muted">اكتب كل مادة في سطر مع ذكر الوزن أو النسبة.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">أدوات التعبئة والأعداد</label>
                    <textarea name="packaging" rows="4" class="form-control" placeholder="مثال:
- برطمان زجاجي 500 جم: 1
- غطاء معدني: 1
- ملصق خارجي: 1"></textarea>
                    <small class="text-muted">اذكر الأدوات المطلوبة وعدد كل منها لكل منتج.</small>
                </div>
                <div class="mb-0">
                    <label class="form-label">ملاحظات الإنشاء</label>
                    <textarea name="notes" rows="3" class="form-control" placeholder="خطوات خاصة بالإنتاج، درجة حرارة، وقت تبريد، ..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>حفظ المواصفة
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editSpecificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form class="modal-content" method="POST">
            <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($formToken); ?>">
            <input type="hidden" name="action" value="edit_specification">
            <input type="hidden" name="spec_id" id="editSpecId" value="">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>تعديل مواصفة المنتج</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                    <input type="text" name="product_name" id="editProductName" class="form-control" required maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label">المواد الخام والأوزان</label>
                    <textarea name="raw_materials" id="editRawMaterials" rows="4" class="form-control"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">أدوات التعبئة والأعداد</label>
                    <textarea name="packaging" id="editPackaging" rows="4" class="form-control"></textarea>
                </div>
                <div class="mb-0">
                    <label class="form-label">ملاحظات الإنشاء</label>
                    <textarea name="notes" id="editNotes" rows="3" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const addSpecModal = document.getElementById('addSpecificationModal');
    if (addSpecModal) {
        addSpecModal.addEventListener('hidden.bs.modal', () => {
            const form = addSpecModal.querySelector('form');
            if (form) {
                form.reset();
            }
        });
    }

    const editSpecModal = document.getElementById('editSpecificationModal');
    if (editSpecModal) {
        const editForm = editSpecModal.querySelector('form');
        const specIdInput = document.getElementById('editSpecId');
        const productNameInput = document.getElementById('editProductName');
        const rawMaterialsInput = document.getElementById('editRawMaterials');
        const packagingInput = document.getElementById('editPackaging');
        const notesInput = document.getElementById('editNotes');

        editSpecModal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            if (!button) {
                return;
            }
            if (specIdInput) {
                specIdInput.value = button.getAttribute('data-id') || '';
            }
            if (productNameInput) {
                productNameInput.value = button.getAttribute('data-name') || '';
            }
            if (rawMaterialsInput) {
                rawMaterialsInput.value = button.getAttribute('data-raw') || '';
            }
            if (packagingInput) {
                packagingInput.value = button.getAttribute('data-packaging') || '';
            }
            if (notesInput) {
                notesInput.value = button.getAttribute('data-notes') || '';
            }
        });

        editSpecModal.addEventListener('hidden.bs.modal', () => {
            if (editForm) {
                editForm.reset();
            }
        });
    }
});
</script>
