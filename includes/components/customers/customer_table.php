<?php

declare(strict_types=1);

/**
 * Shared renderer for customers search + table + pagination.
 */
if (!function_exists('renderCustomerListSection')) {
    /**
     * Render the search card, table and pagination for customers.
     *
     * Expected $config keys:
     * - form_action (string)
     * - hidden_fields (array<string,string>)
     * - search (string)
     * - debt_status (string)
     * - total_customers (int)
     * - customers (array<array>)
     * - pagination => ['page'=>int,'total_pages'=>int,'base_url'=>string]
     * - actions => [
     *       'collect' => bool,
     *       'history' => bool,
     *       'returns' => bool,
     *       'edit' => bool,
     *       'delete' => bool,
     *       'location_capture' => bool,
     *       'location_view' => bool,
     *   ]
     * - current_role (string)
     */
    function renderCustomerListSection(array $config): void
    {
        $formAction = $config['form_action'] ?? '';
        $hiddenFields = $config['hidden_fields'] ?? [];
        $search = trim((string)($config['search'] ?? ''));
        $debtStatus = $config['debt_status'] ?? 'all';
        $customers = $config['customers'] ?? [];
        $totalCustomers = (int)($config['total_customers'] ?? count($customers));
        $pagination = $config['pagination'] ?? [];
        $currentPage = max(1, (int)($pagination['page'] ?? 1));
        $totalPages = max(1, (int)($pagination['total_pages'] ?? 1));
        $baseUrl = $pagination['base_url'] ?? '';
        $currentRole = $config['current_role'] ?? 'manager';
        $actions = array_merge([
            'collect' => true,
            'history' => true,
            'returns' => true,
            'edit' => true,
            'delete' => true,
            'location_capture' => true,
            'location_view' => true,
        ], $config['actions'] ?? []);
        
        // جمع معاملات البحث المتقدم
        $advancedFilters = [
            'search_email' => $_GET['search_email'] ?? '',
            'search_address' => $_GET['search_address'] ?? '',
            'has_location' => $_GET['has_location'] ?? '',
            'balance_min' => $_GET['balance_min'] ?? '',
            'balance_max' => $_GET['balance_max'] ?? '',
        ];

        $queryGlue = (strpos($baseUrl, '?') !== false) ? '&' : '?';
        $paginationBase = $baseUrl === '' ? '#' : $baseUrl;
        ?>

        <div class="card shadow-sm mb-4 customers-search-card">
            <div class="card-body">
                <form method="GET" action="<?php echo htmlspecialchars($formAction); ?>" id="customerSearchForm">
                    <?php foreach ($hiddenFields as $field => $value): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($field); ?>" value="<?php echo htmlspecialchars($value); ?>">
                    <?php endforeach; ?>
                    <div class="row g-2 g-md-3 align-items-end">
                        <div class="col-12 col-md-6 col-lg-5">
                            <label for="customerSearch" class="visually-hidden">بحث عن العملاء</label>
                            <div class="input-group input-group-sm shadow-sm">
                                <span class="input-group-text bg-light text-muted border-end-0">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input
                                    type="text"
                                    class="form-control border-start-0"
                                    id="customerSearch"
                                    name="search"
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="بحث سريع بالاسم أو الهاتف"
                                    autocomplete="off"
                                >
                            </div>
                        </div>
                        <div class="col-6 col-md-3 col-lg-3">
                            <label for="debtStatusFilter" class="visually-hidden">تصفية حسب حالة الديون</label>
                            <select class="form-select form-select-sm shadow-sm" id="debtStatusFilter" name="debt_status">
                                <option value="all" <?php echo $debtStatus === 'all' ? 'selected' : ''; ?>>الكل</option>
                                <option value="debtor" <?php echo $debtStatus === 'debtor' ? 'selected' : ''; ?>>مدين</option>
                                <option value="clear" <?php echo $debtStatus === 'clear' ? 'selected' : ''; ?>>غير مدين / لديه رصيد</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 col-lg-2 d-grid">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-search me-1"></i>
                                <span>بحث</span>
                            </button>
                        </div>
                        <div class="col-12 col-md-3 col-lg-2 d-grid">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#advancedSearchCollapse" aria-expanded="false" aria-controls="advancedSearchCollapse">
                                <i class="bi bi-funnel me-1"></i>
                                <span>بحث متقدم</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- البحث المتقدم -->
                    <div class="collapse mt-3" id="advancedSearchCollapse">
                        <div class="card card-body bg-light border-0">
                            <div class="row g-2 g-md-3">
                                <div class="col-12 col-md-6 col-lg-4">
                                    <label for="searchEmail" class="form-label small">البحث بالبريد الإلكتروني</label>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm"
                                        id="searchEmail"
                                        name="search_email"
                                        value="<?php echo htmlspecialchars($_GET['search_email'] ?? ''); ?>"
                                        placeholder="البحث بالبريد الإلكتروني"
                                    >
                                </div>
                                <div class="col-12 col-md-6 col-lg-4">
                                    <label for="searchAddress" class="form-label small">البحث بالعنوان</label>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm"
                                        id="searchAddress"
                                        name="search_address"
                                        value="<?php echo htmlspecialchars($_GET['search_address'] ?? ''); ?>"
                                        placeholder="البحث بالعنوان"
                                    >
                                </div>
                                <div class="col-12 col-md-6 col-lg-4">
                                    <label for="hasLocation" class="form-label small">حالة الموقع</label>
                                    <select class="form-select form-select-sm" id="hasLocation" name="has_location">
                                        <option value="">الكل</option>
                                        <option value="1" <?php echo (isset($_GET['has_location']) && $_GET['has_location'] === '1') ? 'selected' : ''; ?>>لديه موقع</option>
                                        <option value="0" <?php echo (isset($_GET['has_location']) && $_GET['has_location'] === '0') ? 'selected' : ''; ?>>بدون موقع</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6 col-lg-4">
                                    <label for="balanceMin" class="form-label small">الحد الأدنى للرصيد</label>
                                    <input
                                        type="number"
                                        class="form-control form-control-sm"
                                        id="balanceMin"
                                        name="balance_min"
                                        value="<?php echo htmlspecialchars($_GET['balance_min'] ?? ''); ?>"
                                        placeholder="0.00"
                                        step="0.01"
                                    >
                                </div>
                                <div class="col-12 col-md-6 col-lg-4">
                                    <label for="balanceMax" class="form-label small">الحد الأقصى للرصيد</label>
                                    <input
                                        type="number"
                                        class="form-control form-control-sm"
                                        id="balanceMax"
                                        name="balance_max"
                                        value="<?php echo htmlspecialchars($_GET['balance_max'] ?? ''); ?>"
                                        placeholder="0.00"
                                        step="0.01"
                                    >
                                </div>
                                <div class="col-12 col-md-6 col-lg-4 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="resetAdvancedSearch()">
                                        <i class="bi bi-x-circle me-1"></i>إعادة تعيين
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
        function resetAdvancedSearch() {
            document.getElementById('searchEmail').value = '';
            document.getElementById('searchAddress').value = '';
            document.getElementById('hasLocation').value = '';
            document.getElementById('balanceMin').value = '';
            document.getElementById('balanceMax').value = '';
        }
        
        // فتح البحث المتقدم تلقائياً إذا كان هناك قيم
        document.addEventListener('DOMContentLoaded', function() {
            const hasAdvancedFilters = <?php echo (isset($_GET['search_email']) || isset($_GET['search_address']) || isset($_GET['has_location']) || isset($_GET['balance_min']) || isset($_GET['balance_max'])) ? 'true' : 'false'; ?>;
            if (hasAdvancedFilters) {
                const collapse = document.getElementById('advancedSearchCollapse');
                if (collapse) {
                    const bsCollapse = new bootstrap.Collapse(collapse, { toggle: false });
                    bsCollapse.show();
                }
            }
        });
        </script>

        <div class="card shadow-sm customers-list-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    قائمة العملاء (<?php echo number_format($totalCustomers); ?>)
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive dashboard-table-wrapper customers-table-container">
                    <table class="table dashboard-table align-middle">
                        <thead>
                            <tr>
                                <th>الاسم</th>
                                <th>رقم الهاتف</th>
                                <th>الرصيد</th>
                                <th>العنوان</th>
                                <th>الموقع</th>
                                <th>تاريخ الإضافة</th>
                                <th>الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">لا توجد بيانات متاحة.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $customer): ?>
                                    <?php
                                    $customerBalanceValue = isset($customer['balance']) ? (float)$customer['balance'] : 0.0;
                                    $balanceBadgeClass = $customerBalanceValue > 0
                                        ? 'bg-warning-subtle text-warning'
                                        : ($customerBalanceValue < 0 ? 'bg-info-subtle text-info' : 'bg-secondary-subtle text-secondary');
                                    $displayBalanceValue = $customerBalanceValue < 0 ? abs($customerBalanceValue) : $customerBalanceValue;
                                    $hasLocation = isset($customer['latitude'], $customer['longitude']) &&
                                        $customer['latitude'] !== null &&
                                        $customer['longitude'] !== null;
                                    $latValue = $hasLocation ? (float)$customer['latitude'] : null;
                                    $lngValue = $hasLocation ? (float)$customer['longitude'] : null;
                                    $rawBalance = number_format($customerBalanceValue, 2, '.', '');
                                    $formattedBalance = function_exists('formatCurrency')
                                        ? formatCurrency($displayBalanceValue)
                                        : number_format($displayBalanceValue, 2);
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($customer['name'] ?? '-'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer['phone'] ?? '-'); ?></td>
                                        <td>
                                            <strong><?php echo $formattedBalance; ?></strong>
                                            <?php if ($customerBalanceValue !== 0.0): ?>
                                                <span class="badge <?php echo $balanceBadgeClass; ?> ms-1">
                                                    <?php echo $customerBalanceValue > 0 ? 'رصيد مدين' : 'رصيد دائن'; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($customer['address'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($actions['location_capture'] || $actions['location_view']): ?>
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <?php if ($actions['location_capture']): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-outline-primary location-capture-btn"
                                                            data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                            data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                        >
                                                            <i class="bi bi-geo-alt me-1"></i>تحديد
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($actions['location_view']): ?>
                                                        <?php if ($hasLocation): ?>
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-outline-info location-view-btn"
                                                                data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                                data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                                data-latitude="<?php echo htmlspecialchars(number_format((float)$latValue, 8, '.', '')); ?>"
                                                                data-longitude="<?php echo htmlspecialchars(number_format((float)$lngValue, 8, '.', '')); ?>"
                                                            >
                                                                <i class="bi bi-map me-1"></i>عرض
                                                            </button>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary-subtle text-secondary">غير محدد</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo function_exists('formatDate') ? formatDate($customer['created_at'] ?? '') : htmlspecialchars((string)($customer['created_at'] ?? '-')); ?></td>
                                        <td>
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <?php if ($actions['collect']): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm <?php echo $customerBalanceValue > 0 ? 'btn-success' : 'btn-outline-secondary'; ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#collectPaymentModal"
                                                        data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                        data-customer-balance="<?php echo $rawBalance; ?>"
                                                        data-customer-balance-formatted="<?php echo htmlspecialchars($formattedBalance); ?>"
                                                        <?php echo $customerBalanceValue > 0 ? '' : 'disabled'; ?>
                                                    >
                                                        <i class="bi bi-cash-coin me-1"></i>تحصيل
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($actions['history']): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-dark js-customer-history"
                                                        data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                    >
                                                        <i class="bi bi-journal-text me-1"></i>سجل المشتريات
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($actions['returns']): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-primary js-customer-purchase-history"
                                                        data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                        title="سجل مشتريات العميل - إنشاء مرتجع"
                                                    >
                                                        <i class="bi bi-arrow-return-left me-1"></i>إرجاع
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($actions['edit']): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-secondary edit-customer-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#editCustomerModal"
                                                        data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                        data-customer-phone="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>"
                                                        data-customer-address="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>"
                                                        data-customer-email="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>"
                                                        data-customer-balance="<?php echo htmlspecialchars(number_format((float)$customerBalanceValue, 2, '.', '')); ?>"
                                                    >
                                                        <i class="bi bi-pencil-square me-1"></i>تعديل
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($actions['delete'] && in_array($currentRole, ['manager'], true)): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-danger delete-customer-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteCustomerModal"
                                                        data-customer-id="<?php echo (int)($customer['id'] ?? 0); ?>"
                                                        data-customer-name="<?php echo htmlspecialchars($customer['name'] ?? '-'); ?>"
                                                    >
                                                        <i class="bi bi-trash3 me-1"></i>حذف
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($paginationBase . $queryGlue . 'p=' . max(1, $currentPage - 1) . buildCustomerQuerySuffix($search, $debtStatus, $advancedFilters)); ?>">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            </li>

                            <?php
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);
                            if ($startPage > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo htmlspecialchars($paginationBase . $queryGlue . 'p=1' . buildCustomerQuerySuffix($search, $debtStatus, $advancedFilters)); ?>">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i === $currentPage ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($paginationBase . $queryGlue . 'p=' . $i . buildCustomerQuerySuffix($search, $debtStatus, $advancedFilters)); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo htmlspecialchars($paginationBase . $queryGlue . 'p=' . $totalPages . buildCustomerQuerySuffix($search, $debtStatus, $advancedFilters)); ?>"><?php echo $totalPages; ?></a>
                                </li>
                            <?php endif; ?>

                            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo htmlspecialchars($paginationBase . $queryGlue . 'p=' . min($totalPages, $currentPage + 1) . buildCustomerQuerySuffix($search, $debtStatus, $advancedFilters)); ?>">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('buildCustomerQuerySuffix')) {
    /**
     * Build suffix for pagination links preserving filters.
     */
    function buildCustomerQuerySuffix(string $search, string $debtStatus, array $advancedFilters = []): string
    {
        $params = [];
        if ($search !== '') {
            $params[] = 'search=' . urlencode($search);
        }
        if ($debtStatus !== '') {
            $params[] = 'debt_status=' . urlencode($debtStatus);
        }
        
        // معاملات البحث المتقدم
        if (isset($advancedFilters['search_email']) && $advancedFilters['search_email'] !== '') {
            $params[] = 'search_email=' . urlencode($advancedFilters['search_email']);
        }
        if (isset($advancedFilters['search_address']) && $advancedFilters['search_address'] !== '') {
            $params[] = 'search_address=' . urlencode($advancedFilters['search_address']);
        }
        if (isset($advancedFilters['has_location']) && $advancedFilters['has_location'] !== '') {
            $params[] = 'has_location=' . urlencode($advancedFilters['has_location']);
        }
        if (isset($advancedFilters['balance_min']) && $advancedFilters['balance_min'] !== '') {
            $params[] = 'balance_min=' . urlencode($advancedFilters['balance_min']);
        }
        if (isset($advancedFilters['balance_max']) && $advancedFilters['balance_max'] !== '') {
            $params[] = 'balance_max=' . urlencode($advancedFilters['balance_max']);
        }
        
        return $params ? '&' . implode('&', $params) : '';
    }
}

