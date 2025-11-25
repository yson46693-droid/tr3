<?php

declare(strict_types=1);

/**
 * Helper for rendering the customers page header with tabs/actions.
 */
if (!function_exists('renderCustomersSectionHeader')) {
    /**
     * Render a section header with tabs and optional action buttons.
     *
     * @param array $config {
     *     @var string      $title        Title displayed on the left.
     *     @var string      $active_tab   Currently active tab id.
     *     @var array[]     $tabs         Array of tab definitions [id,label,href,icon].
     *     @var array|null  $primary_btn  Optional main action button [label,icon,attrs].
     *     @var array[]     $extra_btns   Optional secondary buttons.
     * }
     */
    function renderCustomersSectionHeader(array $config): void
    {
        $title = $config['title'] ?? '';
        $activeTab = $config['active_tab'] ?? '';
        $tabs = $config['tabs'] ?? [];
        $primaryButton = $config['primary_btn'] ?? null;
        $extraButtons = $config['extra_btns'] ?? [];
        ?>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
            <h2 class="mb-2 mb-md-0">
                <i class="bi bi-people me-2"></i><?php echo htmlspecialchars($title); ?>
            </h2>
            <div class="d-flex flex-wrap gap-2">
                <?php if (!empty($extraButtons)): ?>
                    <?php foreach ($extraButtons as $button): ?>
                        <?php
                        $label = $button['label'] ?? '';
                        $icon = $button['icon'] ?? '';
                        $attrs = $button['attrs'] ?? [];
                        ?>
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            <?php foreach ($attrs as $attr => $value): ?>
                                <?php echo htmlspecialchars($attr); ?>="<?php echo htmlspecialchars((string) $value); ?>"
                            <?php endforeach; ?>
                        >
                            <?php if ($icon): ?>
                                <i class="<?php echo htmlspecialchars($icon); ?> me-1"></i>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($label); ?>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php if (!empty($primaryButton)): ?>
                <?php
                $label = $primaryButton['label'] ?? '';
                $icon = $primaryButton['icon'] ?? '';
                $attrs = $primaryButton['attrs'] ?? [];
                $tag = $primaryButton['tag'] ?? 'button';
                $primaryClass = $primaryButton['class'] ?? 'btn btn-primary';
                ?>
                <<?php echo $tag; ?>
                    class="<?php echo htmlspecialchars($primaryClass); ?>"
                    <?php foreach ($attrs as $attr => $value): ?>
                        <?php echo htmlspecialchars($attr); ?>="<?php echo htmlspecialchars((string) $value); ?>"
                    <?php endforeach; ?>
                >
                    <?php if ($icon): ?>
                        <i class="<?php echo htmlspecialchars($icon); ?> me-1"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($label); ?>
                </<?php echo $tag; ?>>
            <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($tabs)): ?>
            <ul class="nav nav-pills gap-2 mb-4 customers-tabs">
                <?php foreach ($tabs as $tab): ?>
                    <?php
                    $tabId = $tab['id'] ?? '';
                    $tabLabel = $tab['label'] ?? '';
                    $tabHref = $tab['href'] ?? '#';
                    $tabIcon = $tab['icon'] ?? '';
                    $tabAttrs = $tab['attrs'] ?? [];
                    $isActive = $tabId === $activeTab;
                    ?>
                    <li class="nav-item">
                        <a
                            class="nav-link <?php echo $isActive ? 'active' : ''; ?>"
                            href="<?php echo htmlspecialchars($tabHref); ?>"
                            data-tab-id="<?php echo htmlspecialchars($tabId); ?>"
                            <?php foreach ($tabAttrs as $attr => $value): ?>
                                <?php echo htmlspecialchars($attr); ?>="<?php echo htmlspecialchars((string) $value); ?>"
                            <?php endforeach; ?>
                        >
                            <?php if ($tabIcon): ?>
                                <i class="<?php echo htmlspecialchars($tabIcon); ?> me-2"></i>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($tabLabel); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif;
    }
}

