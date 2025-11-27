<?php

declare(strict_types=1);

/**
 * Representative card grid renderer.
 */
if (!function_exists('renderRepresentativeCards')) {
    /**
     * Render representative cards grid.
     *
     * @param array $representatives Array of reps (id, full_name, phone, email, customer_count, image_url).
     * @param array $options {
     *     @var string $view_base_url Base URL used to open the rep customers page.
     *     @var string $placeholder_image Optional placeholder avatar.
     *     @var bool   $open_in_new_tab Whether to open cards in new tab.
     * }
     */
    function renderRepresentativeCards(array $representatives, array $options = []): void
    {
        $viewBaseUrl = $options['view_base_url'] ?? '#';
        $placeholderImage = $options['placeholder_image'] ?? null;
        $openInNewTab = !empty($options['open_in_new_tab']);
        ?>
        <div class="row g-3 representatives-grid">
            <?php if (empty($representatives)): ?>
                <div class="col-12">
                    <div class="alert alert-light border text-center text-muted mb-0">
                        لا يوجد مندوبون لعرضهم حالياً.
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($representatives as $rep): ?>
                    <?php
                    $repId = (int)($rep['id'] ?? 0);
                    $repName = $rep['full_name'] ?? $rep['username'] ?? '—';
                    $repPhone = $rep['phone'] ?? 'غير متوفر';
                    $repEmail = $rep['email'] ?? '';
                    $customerCount = (int)($rep['customer_count'] ?? 0);
                    $status = strtolower((string)($rep['status'] ?? 'inactive'));
                    $avatar = $rep['profile_image'] ?? $placeholderImage;
                    $queryGlue = (strpos($viewBaseUrl, '?') !== false) ? '&' : '?';
                    $cardUrl = $repId > 0 ? $viewBaseUrl . $queryGlue . 'rep_id=' . $repId : '#';
                    $statusLabel = $status === 'active' ? 'نشط' : 'غير نشط';
                    $statusBadge = $status === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary';
                    ?>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div 
                            class="card representative-card h-100 shadow-sm"
                            style="cursor: pointer;"
                            data-bs-toggle="modal"
                            data-bs-target="#repDetailsModal"
                            data-rep-id="<?php echo $repId; ?>"
                            data-rep-name="<?php echo htmlspecialchars($repName); ?>"
                            onclick="loadRepDetails(<?php echo $repId; ?>, '<?php echo htmlspecialchars($repName, ENT_QUOTES); ?>')"
                        >
                                <div class="card-body d-flex flex-column gap-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rep-avatar">
                                            <?php if ($avatar): ?>
                                                <img src="<?php echo htmlspecialchars($avatar); ?>" alt="<?php echo htmlspecialchars($repName); ?>">
                                            <?php else: ?>
                                                <div class="rep-avatar-placeholder">
                                                    <i class="bi bi-person"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2">
                                                <h5 class="mb-0 text-dark"><?php echo htmlspecialchars($repName); ?></h5>
                                                <span class="badge <?php echo $statusBadge; ?>"><?php echo $statusLabel; ?></span>
                                            </div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($rep['title'] ?? 'مندوب مبيعات'); ?></div>
                                        </div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <div class="rep-stat-card">
                                                <div class="rep-stat-label text-muted">عدد العملاء</div>
                                                <div class="rep-stat-value"><?php echo number_format($customerCount); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (isset($rep['total_collections']) || isset($rep['total_returns'])): ?>
                                    <div class="row g-2">
                                        <?php if (isset($rep['total_collections'])): ?>
                                        <div class="col-6">
                                            <div class="rep-stat-card border-success">
                                                <div class="rep-stat-label text-muted small">التحصيلات</div>
                                                <div class="rep-stat-value text-success small fw-bold"><?php echo formatCurrency((float)($rep['total_collections'] ?? 0)); ?></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (isset($rep['total_returns'])): ?>
                                        <div class="col-6">
                                            <div class="rep-stat-card border-info">
                                                <div class="rep-stat-label text-muted small">المرتجعات</div>
                                                <div class="rep-stat-value text-info small fw-bold"><?php echo formatCurrency((float)($rep['total_returns'] ?? 0)); ?></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-end">
                                        <button 
                                            type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            onclick="event.stopPropagation(); loadRepDetails(<?php echo $repId; ?>, '<?php echo htmlspecialchars($repName, ENT_QUOTES); ?>');"
                                            data-bs-toggle="modal"
                                            data-bs-target="#repDetailsModal"
                                        >
                                            <i class="bi bi-people-fill me-1"></i>
                                            عرض العملاء
                                        </button>
                                    </div>
                                </div>
                            </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}


