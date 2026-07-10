<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function cashFlowUiContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "cash-flow-ui-contract: {$message}\n");
        exit(1);
    }
}

$overview = (string) file_get_contents($root . '/cash_flow_report.php');
$detail = (string) file_get_contents($root . '/drawer_session.php');
$css = (string) file_get_contents($root . '/css/premium-report-light.css');

cashFlowUiContractAssert(
    strpos($overview, "header('Location: drawer_session.php?id='") !== false,
    'overview must redirect legacy drawer_session_id links'
);
cashFlowUiContractAssert(
    strpos($overview, 'drawer_session.php?id=') !== false,
    'overview sessions table must link to drawer_session.php'
);
cashFlowUiContractAssert(
    strpos($overview, 'pr-verdict') !== false && strpos($overview, 'pr-mix') !== false && strpos($overview, 'pr-walk') !== false,
    'overview must render verdict strip, payment mix, and cash walk'
);
cashFlowUiContractAssert(
    strpos($overview, "payments['by_type']") !== false || strpos($overview, '$byType') !== false,
    'overview must render payment by_type mix'
);
cashFlowUiContractAssert(
    strpos($overview, 'pr-tabs') !== false && strpos($overview, 'cash-flow-tab-sessions') !== false,
    'overview must render sessions/movements tabs at the same level'
);
cashFlowUiContractAssert(
    strpos($overview, 'session_page') !== false && strpos($overview, 'session_filter') !== false,
    'overview must paginate and filter sessions'
);
cashFlowUiContractAssert(
    strpos($overview, 'pending_count') !== false && strpos($overview, 'cash-flow-pending-count-banner') !== false,
    'overview must make closed sessions awaiting a count visible and filterable'
);
cashFlowUiContractAssert(
    strpos($overview, 'pr-walk-collapse') !== false,
    'overview must collapse detailed cash walk'
);
cashFlowUiContractAssert(
    strpos($overview, '<details class="pr-collapse"') === false,
    'overview must not bury movements in a collapsed details block'
);
cashFlowUiContractAssert(
    strpos($overview, 'تقرير التدفق النقدي') !== false && strpos($overview, 'name="date_from"') !== false,
    'overview must keep page title and date filter for e2e'
);

cashFlowUiContractAssert(
    strpos($detail, "page_guard('reports.cash_flow'") !== false,
    'detail page must use reports.cash_flow permission'
);
cashFlowUiContractAssert(
    strpos($detail, 'pr-walk') !== false && strpos($detail, 'pr-timeline') !== false,
    'detail page must render cash walk and movement timeline'
);
cashFlowUiContractAssert(
    strpos($detail, 'ShiftDrawerReconciliationService') !== false,
    'detail page must load per-session reconciliation'
);
cashFlowUiContractAssert(
    strpos($detail, 'tenant') !== false && strpos($detail, 'branch') !== false,
    'detail page must validate tenant/branch scope'
);
cashFlowUiContractAssert(
    strpos($detail, 'countPending') !== false && strpos($detail, 'drawer-session-pending-count-banner') !== false,
    'detail must label a missing close count as pending instead of balanced'
);

cashFlowUiContractAssert(
    strpos($css, '.pr-verdict') !== false
    && strpos($css, '.pr-mix') !== false
    && strpos($css, '.pr-walk') !== false
    && strpos($css, '.pr-tabs') !== false
    && strpos($css, '.pr-timeline') !== false,
    'premium CSS must include new cash-flow UX classes'
);

echo "cash-flow-ui-contract-ok\n";
