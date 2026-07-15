<?php
/** @var array $dashboardOverview */
$attention = $dashboardOverview['attention'] ?? [];
$salesStrip = $dashboardOverview['sales_strip'] ?? [];
$currency = htmlspecialchars((string) ($dashboardOverview['context']['currency'] ?? 'ج.م.'), ENT_QUOTES, 'UTF-8');
$reportsUrl = htmlspecialchars((string) ($salesStrip['reports_url'] ?? 'operations_summary.php?q=buy'), ENT_QUOTES, 'UTF-8');
?>
<div class="pr-dashboard-lower">
    <section class="pr-panel" data-testid="dashboard-needs-attention">
        <div class="pr-panel-head">
            <h2 class="pr-panel-title">يحتاج انتباهك</h2>
            <?php if ($attention !== []): ?>
            <span class="pr-pill pr-pill--status-open"><?= count($attention) ?></span>
            <?php else: ?>
            <span class="pr-pill pr-pill--status-closed">مستقر</span>
            <?php endif; ?>
        </div>
        <div class="pr-panel-body">
            <?php if ($attention === []): ?>
            <div class="pr-callout pr-callout--success dashboard-healthy-state">لا يوجد ما يتطلب انتباهك</div>
            <?php else: ?>
            <div class="pr-walk">
                <?php foreach ($attention as $row): ?>
                <a class="pr-walk-row pr-dashboard-attention-row" href="<?= htmlspecialchars((string) ($row['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="pr-walk-label"><?= htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="pr-pill pr-pill--status-open"><?= (int) ($row['count'] ?? 0) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pr-panel" data-testid="dashboard-sales-strip">
        <div class="pr-panel-head">
            <h2 class="pr-panel-title">ملخص المبيعات</h2>
            <a href="<?= $reportsUrl ?>" class="pr-btn pr-btn-ghost">كل التقارير</a>
        </div>
        <div class="pr-panel-body">
            <div class="pr-verdict pr-verdict--compact">
                <div class="pr-verdict-card">
                    <div class="pr-verdict-label">آخر فاتورة</div>
                    <div class="pr-verdict-value pr-verdict-value--sm">
                        <?= htmlspecialchars((string) ($salesStrip['last_invoice_formatted'] ?? DashboardOverviewService::UNAVAILABLE_LABEL), ENT_QUOTES, 'UTF-8') ?>
                        <span class="pr-verdict-unit"><?= $currency ?></span>
                    </div>
                </div>
                <div class="pr-verdict-card">
                    <div class="pr-verdict-label">آخر 7 أيام</div>
                    <div class="pr-verdict-value pr-verdict-value--sm">
                        <?= htmlspecialchars((string) ($salesStrip['last_7d_formatted'] ?? DashboardOverviewService::UNAVAILABLE_LABEL), ENT_QUOTES, 'UTF-8') ?>
                        <span class="pr-verdict-unit"><?= $currency ?></span>
                    </div>
                </div>
                <div class="pr-verdict-card">
                    <div class="pr-verdict-label">آخر 30 يوم</div>
                    <div class="pr-verdict-value pr-verdict-value--sm">
                        <?= htmlspecialchars((string) ($salesStrip['last_30d_formatted'] ?? DashboardOverviewService::UNAVAILABLE_LABEL), ENT_QUOTES, 'UTF-8') ?>
                        <span class="pr-verdict-unit"><?= $currency ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
