<?php

$root = dirname(__DIR__, 2);

$dashboardPhp = file_get_contents($root . '/dashboard.php');
$cards = file_get_contents($root . '/elements/main/main_cards.php');
$elements = file_get_contents($root . '/elements/main/main_element.php');
$tables = file_get_contents($root . '/elements/main/main_tables.php');
$css = file_get_contents($root . '/css/premium-report-light.css');
$service = file_get_contents($root . '/classes/Dashboard/DashboardOverviewService.php');

dashboardContractAssert(
    is_file($root . '/classes/Dashboard/DashboardOverviewService.php'),
    'DashboardOverviewService exists'
);
dashboardContractAssert(
    strpos($dashboardPhp, 'DashboardOverviewService') !== false,
    'dashboard.php wires DashboardOverviewService'
);
dashboardContractAssert(
    strpos($dashboardPhp, 'erp.dashboard.main_cards') !== false
    && strpos($dashboardPhp, 'erp.dashboard.main_elements') !== false
    && strpos($dashboardPhp, 'erp.dashboard.main_tables') !== false,
    'three-tier dashboard permission gates preserved'
);
dashboardContractAssert(
    strpos($dashboardPhp, "'clinic.enabled'") !== false
    && strpos($dashboardPhp, "\$rowstg['showclinc']") !== false
    && strpos($dashboardPhp, "auth_guard_has_legacy_flag('sid_clinics'") !== false,
    'clinic attention is gated by module visibility and role permission'
);
dashboardContractAssert(
    strpos($dashboardPhp, 'dashboard-page-title') !== false
    || strpos($dashboardPhp, '<h1') !== false,
    'dashboard has visible page title'
);
dashboardContractAssert(
    strpos($cards, 'data-testid="dashboard-today-kpis"') !== false,
    'today KPIs test id present'
);
dashboardContractAssert(
    strpos($elements, 'data-testid="dashboard-quick-actions"') !== false,
    'quick actions test id present'
);
dashboardContractAssert(
    strpos($tables, 'data-testid="dashboard-needs-attention"') !== false,
    'needs attention test id present'
);
dashboardContractAssert(
    strpos($tables, 'لا يوجد ما يتطلب انتباهك') !== false,
    'healthy attention empty state present'
);

foreach (['main_cards.php' => $cards, 'main_element.php' => $elements, 'main_tables.php' => $tables] as $name => $src) {
    dashboardContractAssert(
        !preg_match('/href\s*=\s*["\']#["\']/', $src),
        $name . ' must not contain href="#"'
    );
    dashboardContractAssert(
        !preg_match('/#(313647|942C21|E3651D)/i', $src),
        $name . ' must not use dark inline hex skins'
    );
    dashboardContractAssert(
        strpos($src, '$conn->query') === false,
        $name . ' must not run direct SQL queries'
    );
}

dashboardContractAssert(
    strpos($cards, 'آخر حسابات') === false
    && strpos($tables, 'آخر حسابات') === false,
    'latest accounts widget removed'
);
dashboardContractAssert(
    strpos($tables, 'آخر 5 زيارات') === false
    && strpos($cards, 'مرات الدخول') === false,
    'login widgets removed from dashboard'
);
dashboardContractAssert(
    strpos($cards, 'إجمالي الطلبات') === false,
    'false tasks-based order card removed'
);
dashboardContractAssert(
    strpos($service, 'pro_tybe = 9') !== false
    && strpos($service, 'COALESCE(isdeleted, 0) = 0') !== false
    && strpos($service, "payment_status IN ('paid', 'refunded')") !== false,
    'service uses the canonical completed POS-sales filter'
);
dashboardContractAssert(
    strpos($service, 'SELECT COUNT(*) AS c FROM reservations WHERE duration IS NULL') !== false,
    'pending reservations exclude completed visits'
);
dashboardContractAssert(
    strpos($dashboardPhp, 'premium-report-light.css') !== false
    && strpos($dashboardPhp, 'premium-report-page') !== false,
    'dashboard uses premium-report-light theme shell'
);
dashboardContractAssert(
    strpos($css, 'pr-verdict-card') !== false
    && strpos($css, 'pr-dashboard-actions') !== false,
    'premium dashboard styles present'
);
dashboardContractAssert(
    strpos($cards, 'pr-verdict-card') !== false
    && strpos($elements, 'pr-btn') !== false
    && strpos($tables, 'pr-panel') !== false,
    'dashboard partials use premium-report markup'
);

echo "dashboard-redesign-contract-ok\n";

function dashboardContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "dashboard-redesign-contract-fail: {$message}\n");
        exit(1);
    }
}
