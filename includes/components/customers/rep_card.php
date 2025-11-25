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
                        <a
                            href="<?php echo htmlspecialchars($cardUrl); ?>"
                            class="text-decoration-none representative-card-link"
                            <?php echo $openInNewTab ? 'target="_blank"' : ''; ?>
                        >
                            <div class="card representative-card h-100 shadow-sm">
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
                                        <div class="col-6">
                                            <div class="rep-stat-card">
                                                <div class="rep-stat-label text-muted">آخر دخول</div>
                                                <div class="rep-stat-value small">
                                                    <?php echo !empty($rep['last_login_at']) ? htmlspecialchars(formatDate($rep['last_login_at'])) : 'غير متوفر'; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="rep-contact-list small text-muted">
                                        <div><i class="bi bi-telephone me-2"></i><?php echo htmlspecialchars($repPhone); ?></div>
                                        <?php if ($repEmail): ?>
                                            <div><i class="bi bi-envelope me-2"></i><?php echo htmlspecialchars($repEmail); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-people-fill me-1"></i>
                                            عرض العملاء
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

