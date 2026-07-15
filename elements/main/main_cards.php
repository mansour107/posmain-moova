<?php
/** @var array $dashboardOverview */
$kpis = $dashboardOverview['kpis'] ?? [];
?>
<section class="pr-verdict dashboard-today-kpis" data-testid="dashboard-today-kpis" aria-label="مؤشرات اليوم">
    <?php foreach ($kpis as $kpi): ?>
    <?php
        $available = !empty($kpi['available']);
        $url = (string) ($kpi['url'] ?? 'operations_summary.php?q=buy');
        $label = (string) ($kpi['label'] ?? '');
        $formatted = (string) ($kpi['formatted'] ?? DashboardOverviewService::UNAVAILABLE_LABEL);
        $key = htmlspecialchars((string) ($kpi['key'] ?? ''), ENT_QUOTES, 'UTF-8');
    ?>
    <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
       class="pr-verdict-card pr-verdict-card--link<?= $available ? '' : ' is-unavailable' ?>"
       data-kpi="<?= $key ?>">
        <div class="pr-verdict-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="pr-verdict-value"><?= htmlspecialchars($formatted, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="pr-verdict-sub">عرض التفاصيل</div>
    </a>
    <?php endforeach; ?>
</section>
