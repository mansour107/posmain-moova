<?php
/** @var array $dashboardOverview */
$actions = $dashboardOverview['quick_actions'] ?? [];
?>
<section class="pr-panel dashboard-quick-actions" data-testid="dashboard-quick-actions">
    <div class="pr-panel-head">
        <h2 class="pr-panel-title">إجراءات سريعة</h2>
        <span class="pr-pill pr-pill--muted"><?= count($actions) ?></span>
    </div>
    <div class="pr-panel-body">
        <?php if ($actions === []): ?>
        <p class="pr-empty">لا توجد إجراءات سريعة متاحة لصلاحياتك الحالية.</p>
        <?php else: ?>
        <div class="pr-dashboard-actions">
            <?php foreach ($actions as $action): ?>
            <a href="<?= htmlspecialchars((string) ($action['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
               class="pr-btn pr-btn-ghost pr-dashboard-action"
               data-permission="<?= htmlspecialchars((string) ($action['permission'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <i class="<?= htmlspecialchars((string) ($action['icon'] ?? 'fas fa-link'), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars((string) ($action['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
