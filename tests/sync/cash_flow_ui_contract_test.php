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
    strpos($overview, "header('Location: ' . posmain_cash_shift_detail_url") !== false,
    'overview must redirect legacy drawer_session_id links'
);
cashFlowUiContractAssert(
    strpos($overview, 'posmain_cash_shift_detail_url') !== false,
    'overview sessions table must link to drawer_session.php'
);
cashFlowUiContractAssert(
    strpos($overview, 'operations-metrics') !== false && strpos($overview, 'operations-payment-stack') !== false && strpos($overview, 'cash-walk') !== false,
    'overview must render sales metrics, payment mix, and cash walk'
);
cashFlowUiContractAssert(
    strpos($overview, "payments['by_type']") !== false || strpos($overview, '$byType') !== false,
    'overview must render payment by_type mix'
);
cashFlowUiContractAssert(
    strpos($overview, 'cash-shift-tabs') !== false && strpos($overview, 'CashShiftWorkspaceService::TAB_SHIFTS') !== false,
    'workspace must render its canonical report tabs at the same level'
);
cashFlowUiContractAssert(
    strpos($overview, "'page' =>") !== false && strpos($overview, "'status' =>") !== false,
    'overview must paginate and filter sessions'
);
cashFlowUiContractAssert(
    strpos($overview, "'scope'=>'backlog'") !== false && strpos($overview, 'كل الفترات') !== false,
    'workspace must make the cross-period review backlog explicit'
);
cashFlowUiContractAssert(
    strpos($overview, '<details class="pr-collapse"') === false,
    'overview must not bury movements in a collapsed details block'
);
cashFlowUiContractAssert(
    strpos($overview, 'تقارير التشغيل') !== false && strpos($overview, 'name="date_from"') !== false,
    'workspace must keep the unified title and date filter for e2e'
);
cashFlowUiContractAssert(
    strpos($overview, 'جميع موظفي الكاشير') !== false
    && strpos($overview, 'الكاشيرين') === false,
    'cashier filter must use clear, standard Arabic wording'
);
cashFlowUiContractAssert(
    strpos($overview, 'cash-shift-date-presets') !== false && strpos($overview, 'aria-current="page"') !== false,
    'workspace must expose accessible one-click business-day ranges'
);
cashFlowUiContractAssert(
    strpos($overview, 'FOCUS_ORDER_CANCELLED') !== false
    && strpos($overview, 'FOCUS_PAYMENT_REFUNDS') !== false
    && strpos($overview, '$attentionUrl($alert)') !== false,
    'attention cards must open a focused, meaningful destination'
);
cashFlowUiContractAssert(
    strpos($overview, 'طلبات أُلغيت بعد إنشائها') !== false
    && strpos($overview, 'عرض الطلبات الملغاة فقط') !== false,
    'attention copy must explain the condition and the destination in plain Arabic'
);
cashFlowUiContractAssert(
    strpos($overview, 'ملغى محاسبيًا') === false
    && strpos($overview, 'يُسجل التأكيد الفرق محاسبيًا') === false
    && strpos($overview, 'تسجيل سبب الفرق') !== false,
    'manager-facing actions and order states must avoid unexplained accounting jargon'
);
cashFlowUiContractAssert(
    strpos($css, '.operations-metric--primary') !== false
    && strpos($css, 'background: linear-gradient(145deg, #1a2f5a, #294774)') === false,
    'net totals must use the same light metric-card language as the other totals'
);

cashFlowUiContractAssert(
    strpos($detail, 'page_guard(null, $conn)') !== false && strpos($detail, '$canReport') !== false,
    'detail page must use route any-of authorization and fine-grained report checks'
);
cashFlowUiContractAssert(strpos($detail, '$returnTo') !== false, 'detail page must preserve workspace return context');
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
    && strpos($css, '.cash-shift-tabs') !== false
    && strpos($css, '.pr-timeline') !== false,
    'premium CSS must include new cash-flow UX classes'
);

echo "cash-flow-ui-contract-ok\n";
