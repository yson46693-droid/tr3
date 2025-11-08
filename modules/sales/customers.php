
<?php
/**
 * صفحة إدارة العملاء للمندوب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['sales', 'accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';
$section = $_GET['section'] ?? ($_POST['section'] ?? 'company');
$allowedSections = ['company', 'delegates'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'company';
}
$baseQueryString = '?page=customers';
if ($section) {
    $baseQueryString .= '&section=' . urlencode($section);
}
$customerStats = [
    'total_count' => 0,
    'debtor_count' => 0,
    'total_debt' => 0.0,
];
$totalCollectionsAmount = 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);

    if ($action === 'collect_debt') {
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $amount = isset($_POST['amount']) ? cleanFinancialValue($_POST['amount']) : 0;

        if ($customerId <= 0) {
            $error = 'معرف العميل غير صالح.';
        } elseif ($amount <= 0) {
            $error = 'يجب إدخال مبلغ تحصيل أكبر من صفر.';
        } else {
            $transactionStarted = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $customer = $db->queryOne(
                    "SELECT id, name, balance FROM customers WHERE id = ? FOR UPDATE",
                    [$customerId]
                );

                if (!$customer) {
                    throw new InvalidArgumentException('لم يتم العثور على العميل المطلوب.');
                }

                $currentBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;

                if ($currentBalance <= 0) {
                    throw new InvalidArgumentException('لا توجد ديون نشطة على هذا العميل.');
                }

                if ($amount > $currentBalance) {
                    throw new InvalidArgumentException('المبلغ المدخل أكبر من ديون العميل الحالية.');
                }

                $newBalance = round(max($currentBalance - $amount, 0), 2);

                $db->execute(
                    "UPDATE customers SET balance = ? WHERE id = ?",
                    [$newBalance, $customerId]
                );

                logAudit(
                    $currentUser['id'],
                    'collect_customer_debt',
                    'customer',
                    $customerId,
                    null,
                    [
                        'collected_amount'   => $amount,
                        'previous_balance'   => $currentBalance,
                        'new_balance'        => $newBalance,
                    ]
                );

                $db->commit();
                $transactionStarted = false;

                $success = 'تم تحصيل المبلغ بنجاح.';
            } catch (InvalidArgumentException $userError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $error = $userError->getMessage();
            } catch (Throwable $collectionError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('Customer collection error: ' . $collectionError->getMessage());
                $error = 'حدث خطأ أثناء تحصيل المبلغ. يرجى المحاولة مرة أخرى.';
            }
        }
    } elseif ($action === 'add_customer') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $balance = isset($_POST['balance']) ? cleanFinancialValue($_POST['balance']) : 0;

        if ($balance < 0) {
            $balance = 0;
        }

        if (empty($name)) {
            $error = 'يجب إدخال اسم العميل';
        } else {
            try {
                if (!empty($phone)) {
                    $existing = $db->queryOne("SELECT id FROM customers WHERE phone = ?", [$phone]);
                    if ($existing) {
                        throw new InvalidArgumentException('رقم الهاتف موجود بالفعل');
                    }
                }

                $result = $db->execute(
                    "INSERT INTO customers (name, phone, balance, address, status, created_by) 
                     VALUES (?, ?, ?, ?, 'active', ?)",
                    [$name, $phone ?: null, $balance, $address ?: null, $currentUser['id']]
                );

                logAudit($currentUser['id'], 'add_customer', 'customer', $result['insert_id'], null, [
                    'name' => $name
                ]);

                $success = 'تم إضافة العميل بنجاح';
            } catch (InvalidArgumentException $userError) {
                $error = $userError->getMessage();
            } catch (Throwable $addCustomerError) {
                error_log('Add customer error: ' . $addCustomerError->getMessage());
                $error = 'حدث خطأ أثناء إضافة العميل. يرجى المحاولة لاحقاً.';
            }
        }
    } elseif ($action === 'update_location') {
        header('Content-Type: application/json; charset=utf-8');

        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $latitude = $_POST['latitude'] ?? null;
        $longitude = $_POST['longitude'] ?? null;

        if ($customerId <= 0 || $latitude === null || $longitude === null) {
            echo json_encode([
                'success' => false,
                'message' => 'بيانات الموقع غير مكتملة.',
            ]);
            exit;
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            echo json_encode([
                'success' => false,
                'message' => 'إحداثيات الموقع غير صالحة.',
            ]);
            exit;
        }

        $latitude = (float)$latitude;
        $longitude = (float)$longitude;

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            echo json_encode([
                'success' => false,
                'message' => 'نطاق الإحداثيات غير صحيح.',
            ]);
            exit;
        }

        try {
            $customer = $db->queryOne("SELECT id FROM customers WHERE id = ?", [$customerId]);
            if (!$customer) {
                throw new InvalidArgumentException('العميل المطلوب غير موجود.');
            }

            $db->execute(
                "UPDATE customers SET latitude = ?, longitude = ?, location_captured_at = NOW() WHERE id = ?",
                [$latitude, $longitude, $customerId]
            );

            logAudit(
                $currentUser['id'],
                'update_customer_location',
                'customer',
                $customerId,
                null,
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ]
            );

            echo json_encode([
                'success' => true,
                'message' => 'تم تحديث موقع العميل بنجاح.',
            ]);
        } catch (InvalidArgumentException $invalidLocation) {
            echo json_encode([
                'success' => false,
                'message' => $invalidLocation->getMessage(),
            ]);
        } catch (Throwable $updateLocationError) {
            error_log('Update customer location error: ' . $updateLocationError->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ الموقع. حاول مرة أخرى.',
            ]);
        }

        exit;
    }
}

try {
    $createdByColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'created_by'");
    if (empty($createdByColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN created_by INT NULL AFTER status");
        $db->execute("ALTER TABLE customers ADD CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
    }
} catch (Throwable $migrationError) {
    error_log('Customers created_by migration error: ' . $migrationError->getMessage());
}

try {
    $latitudeColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'latitude'");
    if (empty($latitudeColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN latitude DECIMAL(10, 8) NULL AFTER address");
    }

    $longitudeColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'longitude'");
    if (empty($longitudeColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN longitude DECIMAL(11, 8) NULL AFTER latitude");
    }

    $locationCapturedColumn = $db->query("SHOW COLUMNS FROM customers LIKE 'location_captured_at'");
    if (empty($locationCapturedColumn)) {
        $db->execute("ALTER TABLE customers ADD COLUMN location_captured_at DATETIME NULL AFTER longitude");
    }
} catch (Throwable $locationMigrationError) {
    error_log('Customers location migration error: ' . $locationMigrationError->getMessage());
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// البحث والفلترة
$search = trim($_GET['search'] ?? '');
$debtStatus = $_GET['debt_status'] ?? 'all';
$allowedDebtStatuses = ['all', 'debtor', 'clear'];
if (!in_array($debtStatus, $allowedDebtStatuses, true)) {
    $debtStatus = 'all';
}

// بناء استعلام SQL
$sql = "SELECT c.*, u.full_name as created_by_name
        FROM customers c
        LEFT JOIN users u ON c.created_by = u.id
        WHERE 1=1";

$countSql = "SELECT COUNT(*) as total FROM customers WHERE 1=1";
$statsSql = "SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) AS debtor_count,
                COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS total_debt
            FROM customers
            WHERE 1=1";
$params = [];
$countParams = [];
$statsParams = [];

if ($debtStatus === 'debtor') {
    $sql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    $countSql .= " AND (balance IS NOT NULL AND balance > 0)";
    $statsSql .= " AND (balance IS NOT NULL AND balance > 0)";
} elseif ($debtStatus === 'clear') {
    $sql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    $countSql .= " AND (balance IS NULL OR balance <= 0)";
    $statsSql .= " AND (balance IS NULL OR balance <= 0)";
}

if ($search) {
    $sql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
    $countSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $statsSql .= " AND (name LIKE ? OR phone LIKE ? OR address LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
    $statsParams[] = $searchParam;
}

$totalResult = $db->queryOne($countSql, $countParams);
$totalCustomers = $totalResult['total'] ?? 0;
$totalPages = ceil($totalCustomers / $perPage);

$sql .= " ORDER BY c.name ASC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$customers = $db->query($sql, $params);
$statsResult = $db->queryOne($statsSql, $statsParams);
if (!empty($statsResult)) {
    $customerStats['total_count'] = (int)($statsResult['total_count'] ?? 0);
    $customerStats['debtor_count'] = (int)($statsResult['debtor_count'] ?? 0);
    $customerStats['total_debt'] = (float)($statsResult['total_debt'] ?? 0);
}

try {
    $collectionsStatusExists = false;
    $statusCheck = $db->query("SHOW COLUMNS FROM collections LIKE 'status'");
    if (!empty($statusCheck)) {
        $collectionsStatusExists = true;
    }

    $collectionsSql = "SELECT COALESCE(SUM(col.amount), 0) AS total_collections
                       FROM collections col
                       INNER JOIN customers c ON col.customer_id = c.id
                       WHERE 1=1";
    $collectionsParams = [];

    if ($collectionsStatusExists) {
        $collectionsSql .= " AND col.status = 'approved'";
    }

    if ($debtStatus === 'debtor') {
        $collectionsSql .= " AND (c.balance IS NOT NULL AND c.balance > 0)";
    } elseif ($debtStatus === 'clear') {
        $collectionsSql .= " AND (c.balance IS NULL OR c.balance <= 0)";
    }

    if ($search) {
        $collectionsSql .= " AND (c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ?)";
        $collectionsParams[] = '%' . $search . '%';
        $collectionsParams[] = '%' . $search . '%';
        $collectionsParams[] = '%' . $search . '%';
    }

    $collectionsResult = $db->queryOne($collectionsSql, $collectionsParams);
    if (!empty($collectionsResult)) {
        $totalCollectionsAmount = (float)($collectionsResult['total_collections'] ?? 0);
    }
} catch (Throwable $collectionsError) {
    error_log('Customers collections summary error: ' . $collectionsError->getMessage());
}

$summaryDebtorCount = $customerStats['debtor_count'] ?? 0;
$summaryTotalDebt = $customerStats['total_debt'] ?? 0.0;
$summaryTotalCustomers = $customerStats['total_count'] ?? $totalCustomers;
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
    <h2 class="mb-2 mb-md-0"><i class="bi bi-people me-2"></i>العملاء</h2>
    <?php if ($section === 'company'): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
        <i class="bi bi-person-plus me-2"></i>إضافة عميل جديد
    </button>
    <?php endif; ?>
</div>

<ul class="nav nav-pills gap-2 mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $section === 'company' ? 'active' : ''; ?>" href="<?php echo getRelativeUrl('sales.php?page=customers&section=company'); ?>">
            <i class="bi bi-building me-2"></i>عملاء الشركة
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $section === 'delegates' ? 'active' : ''; ?>" href="<?php echo getRelativeUrl('sales.php?page=customers&section=delegates'); ?>">
            <i class="bi bi-people-fill me-2"></i>عملاء المندوبين
        </a>
    </li>
</ul>

<?php if ($section === 'company'): ?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">عدد العملاء</div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format((int)$summaryTotalCustomers); ?></div>
                </div>
                <span class="text-primary display-6"><i class="bi bi-people-fill"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">العملاء المدينون</div>
                    <div class="fs-4 fw-bold mb-0"><?php echo number_format((int)$summaryDebtorCount); ?></div>
                </div>
                <span class="text-danger display-6"><i class="bi bi-exclamation-circle-fill"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">إجمالي الديون</div>
                    <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($summaryTotalDebt); ?></div>
                </div>
                <span class="text-warning display-6"><i class="bi bi-cash-stack"></i></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold">إجمالي التحصيلات</div>
                    <div class="fs-5 fw-bold mb-0"><?php echo formatCurrency($totalCollectionsAmount); ?></div>
                </div>
                <span class="text-success display-6"><i class="bi bi-wallet2"></i></span>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <div class="display-5 text-muted mb-3"><i class="bi bi-tools"></i></div>
        <h4 class="mb-2">قسم عملاء المندوبين</h4>
        <p class="text-muted mb-0">هذا القسم قيد التطوير وسيتم توفيره قريبًا.</p>
    </div>
</div>

<?php endif; ?>

<?php if ($section === 'company'): ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var collectionModal = document.getElementById('collectPaymentModal');
    if (collectionModal) {
        var nameElement = collectionModal.querySelector('.collection-customer-name');
        var debtElement = collectionModal.querySelector('.collection-current-debt');
        var customerIdInput = collectionModal.querySelector('input[name="customer_id"]');
        var amountInput = collectionModal.querySelector('input[name="amount"]');

        if (nameElement && debtElement && customerIdInput && amountInput) {
            collectionModal.addEventListener('show.bs.modal', function (event) {
                var triggerButton = event.relatedTarget;
                if (!triggerButton) {
                    return;
                }

                var customerName = triggerButton.getAttribute('data-customer-name') || '-';
                var balanceRaw = triggerButton.getAttribute('data-customer-balance') || '0';
                var balanceFormatted = triggerButton.getAttribute('data-customer-balance-formatted') || balanceRaw;

                nameElement.textContent = customerName;
                debtElement.textContent = balanceFormatted;
                customerIdInput.value = triggerButton.getAttribute('data-customer-id') || '';

                amountInput.value = balanceRaw;
                amountInput.setAttribute('max', balanceRaw);
                amountInput.focus();
            });

            collectionModal.addEventListener('hidden.bs.modal', function () {
                nameElement.textContent = '-';
                debtElement.textContent = '-';
                customerIdInput.value = '';
                amountInput.value = '';
                amountInput.removeAttribute('max');
            });
        }
    }

    var locationCaptureButtons = document.querySelectorAll('.location-capture-btn');
    var viewLocationModal = document.getElementById('viewLocationModal');
    var locationMapFrame = viewLocationModal ? viewLocationModal.querySelector('.location-map-frame') : null;
    var locationCustomerName = viewLocationModal ? viewLocationModal.querySelector('.location-customer-name') : null;
    var locationExternalLink = viewLocationModal ? viewLocationModal.querySelector('.location-open-map') : null;
    var locationViewButtons = document.querySelectorAll('.location-view-btn');

    function setButtonLoading(button, isLoading) {
        if (!button) {
            return;
        }

        if (isLoading) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>جارٍ التحديد';
        } else {
            if (button.dataset.originalHtml) {
                button.innerHTML = button.dataset.originalHtml;
                delete button.dataset.originalHtml;
            }
            button.disabled = false;
        }
    }

    function showAlert(message) {
        window.alert(message);
    }

    locationCaptureButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var customerId = button.getAttribute('data-customer-id');
            var customerName = button.getAttribute('data-customer-name') || '';

            if (!customerId) {
                showAlert('تعذر تحديد العميل.');
                return;
            }

            if (!navigator.geolocation) {
                showAlert('المتصفح الحالي لا يدعم تحديد الموقع الجغرافي.');
                return;
            }

            setButtonLoading(button, true);

            navigator.geolocation.getCurrentPosition(function (position) {
                var latitude = position.coords.latitude.toFixed(8);
                var longitude = position.coords.longitude.toFixed(8);
                var requestUrl = window.location.pathname + window.location.search;

                var formData = new URLSearchParams();
                formData.append('action', 'update_location');
                formData.append('customer_id', customerId);
                formData.append('latitude', latitude);
                formData.append('longitude', longitude);

                fetch(requestUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                }).then(function (response) {
                    return response.json();
                }).then(function (data) {
                    if (data && data.success) {
                        showAlert('تم حفظ موقع العميل ' + customerName + ' بنجاح.');
                        window.location.reload();
                    } else {
                        setButtonLoading(button, false);
                        showAlert(data && data.message ? data.message : 'تعذر حفظ الموقع.');
                    }
                }).catch(function () {
                    setButtonLoading(button, false);
                    showAlert('حدث خطأ أثناء الاتصال بالخادم.');
                });
            }, function (error) {
                setButtonLoading(button, false);
                var errorMessage = 'تعذر الحصول على الموقع الحالي.';
                if (error && error.code === error.PERMISSION_DENIED) {
                    errorMessage = 'تم رفض صلاحية الوصول إلى الموقع. يرجى السماح بذلك من إعدادات المتصفح.';
                } else if (error && error.code === error.POSITION_UNAVAILABLE) {
                    errorMessage = 'معلومات الموقع غير متاحة حالياً.';
                } else if (error && error.code === error.TIMEOUT) {
                    errorMessage = 'انتهى الوقت قبل الحصول على الموقع. حاول مرة أخرى.';
                }
                showAlert(errorMessage);
            }, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            });
        });
    });

    if (viewLocationModal && locationMapFrame && locationCustomerName && locationExternalLink) {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');
                var customerName = button.getAttribute('data-customer-name') || '-';

                if (!latitude || !longitude) {
                    showAlert('لا يوجد موقع مسجل لهذا العميل.');
                    return;
                }

                locationCustomerName.textContent = customerName;
                var embedUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16&output=embed';
                var externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                locationMapFrame.src = embedUrl;
                locationExternalLink.href = externalUrl;

                if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                    var modalInstance = window.bootstrap.Modal.getOrCreateInstance(viewLocationModal);
                    modalInstance.show();
                } else {
                    window.open(externalUrl, '_blank');
                }
            });
        });

        viewLocationModal.addEventListener('hidden.bs.modal', function () {
            locationMapFrame.src = '';
            locationCustomerName.textContent = '-';
            locationExternalLink.href = '#';
        });
    } else {
        locationViewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var latitude = button.getAttribute('data-latitude');
                var longitude = button.getAttribute('data-longitude');
                if (!latitude || !longitude) {
                    showAlert('لا يوجد موقع مسجل لهذا العميل.');
                    return;
                }
                var externalUrl = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&hl=ar&z=16';
                window.open(externalUrl, '_blank');
            });
        });
    }
});
</script>

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

<!-- البحث -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="page" value="customers">
            <?php if ($section): ?>
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
            <?php endif; ?>
            <div class="col-md-8">
                <input type="text" class="form-control" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="ابحث بالاسم، رقم الهاتف، أو العنوان...">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="debt_status">
                    <option value="all" <?php echo $debtStatus === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="debtor" <?php echo $debtStatus === 'debtor' ? 'selected' : ''; ?>>مدين</option>
                    <option value="clear" <?php echo $debtStatus === 'clear' ? 'selected' : ''; ?>>غير مدين</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-2"></i>بحث
                </button>
            </div>
        </form>
    </div>
</div>

<!-- قائمة العملاء -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة عملاء الشركة (<?php echo $totalCustomers; ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>رقم الهاتف</th>
                        <th>ديون العميل</th>
                        <th>العنوان</th>
                        <th>الموقع</th>
                        <th>تاريخ الإضافة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">لا توجد عملاء</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                <td><?php echo formatCurrency($customer['balance'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                <td>
                                    <?php
                                    $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                                        $customer['latitude'] !== null &&
                                        $customer['longitude'] !== null;
                                    $latValue = $hasLocation ? (float)$customer['latitude'] : null;
                                    $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
                                    ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary location-capture-btn"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                        >
                                            <i class="bi bi-geo-alt me-1"></i>تحديد
                                        </button>
                                        <?php if ($hasLocation): ?>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-info location-view-btn"
                                                data-customer-id="<?php echo (int)$customer['id']; ?>"
                                                data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                                data-latitude="<?php echo htmlspecialchars(number_format($latValue, 8, '.', '')); ?>"
                                                data-longitude="<?php echo htmlspecialchars(number_format($lngValue, 8, '.', '')); ?>"
                                            >
                                                <i class="bi bi-map me-1"></i>عرض
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo formatDate($customer['created_at']); ?></td>
                                <td>
                                    <?php
                                    $customerBalance = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                                    $formattedBalance = formatCurrency($customerBalance);
                                    $rawBalance = number_format($customerBalance, 2, '.', '');
                                    ?>
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <button
                                            type="button"
                                            class="btn btn-sm <?php echo $customerBalance > 0 ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                            data-bs-toggle="modal"
                                            data-bs-target="#collectPaymentModal"
                                            data-customer-id="<?php echo (int)$customer['id']; ?>"
                                            data-customer-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-customer-balance="<?php echo $rawBalance; ?>"
                                            data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                                            <?php echo $customerBalance > 0 ? '' : 'disabled'; ?>
                                        >
                                            <i class="bi bi-cash-coin me-1"></i>تحصيل
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?php echo $baseQueryString; ?>&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo $baseQueryString; ?>&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Modal تحصيل ديون العميل -->
<div class="modal fade" id="collectPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>تحصيل ديون العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="collect_debt">
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
                <input type="hidden" name="customer_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">العميل</div>
                        <div class="fs-5 collection-customer-name">-</div>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-muted">الديون الحالية</div>
                        <div class="fs-5 text-warning collection-current-debt">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="collectionAmount">مبلغ التحصيل <span class="text-danger">*</span></label>
                        <input
                            type="number"
                            class="form-control"
                            id="collectionAmount"
                            name="amount"
                            step="0.01"
                            min="0.01"
                            required
                        >
                        <div class="form-text">لن يتم قبول مبلغ أكبر من قيمة الديون الحالية.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">تحصيل المبلغ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal عرض موقع العميل -->
<div class="modal fade" id="viewLocationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>موقع العميل</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="text-muted small fw-semibold">العميل</div>
                    <div class="fs-5 fw-bold location-customer-name">-</div>
                </div>
                <div class="ratio ratio-16x9">
                    <iframe
                        class="location-map-frame border rounded"
                        src=""
                        title="معاينة موقع العميل"
                        allowfullscreen
                        loading="lazy"
                    ></iframe>
                </div>
                <p class="mt-3 text-muted mb-0">
                    يمكنك متابعة الموقع داخل المعاينة أو فتحه في خرائط Google للحصول على اتجاهات دقيقة.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <a href="#" target="_blank" rel="noopener" class="btn btn-primary location-open-map">
                    <i class="bi bi-map"></i> فتح في الخرائط
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة عميل جديد -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عميل جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="action" value="add_customer">
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم العميل <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">رقم الهاتف</label>
                        <input type="text" class="form-control" name="phone" placeholder="مثال: 01234567890">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ديون العميل</label>
                        <input type="number" class="form-control" name="balance" step="0.01" min="0" value="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">العنوان</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>
