<?php

/**
 * Drawer session page: simple admin language, opening section, cash-only walk,
 * payments without invoice jargon, expandable movement details.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/classes/Pos/Service/DrawerSessionService.php');
$page = file_get_contents($root . '/drawer_session.php');

function drawerOpenVarianceAssert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

drawerOpenVarianceAssert(
    strpos($service, '$closeVarianceKnown = $countedCash !== null') !== false,
    'sessionCashBreakdown must treat missing counted_cash as unknown close variance'
);

drawerOpenVarianceAssert(
    strpos($service, "'close_variance' => \$closeVarianceKnown ? \$this->formatDecimal(\$closeVariance) : null") !== false,
    'close_variance must stay null until a closing count exists'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-opening"') !== false
        && strpos($page, 'المتوقع عند الافتتاح') !== false
        && strpos($page, 'ما تم عده في الدرج') !== false,
    'opening must be its own simple section'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-close-summary"') !== false
        && strpos($page, 'ملخص الإغلاق') !== false
        && strpos($page, '<?php if (!$isOpen): ?>') !== false,
    'close summary must exist only for closed shifts'
);

drawerOpenVarianceAssert(
    strpos($page, 'المتوقع ← ما تم عده ← الفرق') === false
        && strpos($page, 'pr-verdict-step') === false,
    'must not show formula arrow or card enumeration'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-review-opening"') !== false
        && strpos($page, "csrf_token('shift_resolve')") !== false
        && strpos($page, 'data-testid="drawer-session-resolve-modal"') !== false
        && strpos($page, 'do/do_resolve_drawer_session.php') !== false,
    'يحتاج مراجعة must open the resolve variance modal with a pre-header CSRF mint'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-reviewed-opening"') !== false
        && strpos($page, 'data-testid="drawer-session-reviewed-modal"') !== false
        && strpos($page, 'تفاصيل المراجعة') !== false,
    'تمت المراجعة must open a review-details modal'
);

drawerOpenVarianceAssert(
    strpos($page, 'هذا لا يغيّر أرقام العد') !== false
        && strpos($page, 'سبب الفرق') !== false
        && strpos($page, 'name="resolution_reason_code"') !== false,
    'resolve modal must explain acknowledge review and require a structured reason'
);

drawerOpenVarianceAssert(
    strpos($page, 'جلسة مفتوحة') === false
        || strpos($page, "\$varianceLabel = 'جلسة مفتوحة'") === false,
    'must not use جلسة مفتوحة as the difference card title'
);

drawerOpenVarianceAssert(
    strpos($page, "\$varianceLabel = 'الفرق'") !== false
        || substr_count($page, '<div class="pr-verdict-label">الفرق</div>') >= 1,
    'difference card must stay labeled الفرق'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-sale-cash"') === false,
    'top strip must not dilute the close story with a sales card'
);

drawerOpenVarianceAssert(
    strpos($page, 'زيادة افتتاح') === false
        && strpos($page, 'غير محسوم') === false,
    'must not use confusing opening hero jargon'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-close-variance-row"') !== false
        && strpos($page, 'فرق الإغلاق') !== false,
    'close variance must stay explicit in the cash walk'
);

drawerOpenVarianceAssert(
    strpos($page, 'مسار النقد في الدرج') !== false
        && strpos($page, "'opening', '',") !== false,
    'opening walk row must not use a leading + sign'
);

drawerOpenVarianceAssert(
    strpos($page, 'إجمالي مدفوعات الفواتير') === false
        && strpos($page, 'نقدي في الفواتير') === false
        && strpos($page, 'مدفوعات المبيعات (فواتير)') === false
        && strpos($page, 'طرق الدفع') !== false,
    'must drop invoice jargon and use طرق الدفع'
);

drawerOpenVarianceAssert(
    strpos($page, 'data-testid="drawer-session-cash-bridge"') === false,
    'must not show confusing cash-bridge cards'
);

drawerOpenVarianceAssert(
    strpos($page, 'pr-timeline-item--expandable') !== false
        && strpos($page, "\$friendlyReason(\$row['reason']") !== false
        && strpos($page, 'سبب: <?= htmlspecialchars((string) ($row[\'reason\']') === false,
    'movement details must be expandable and hide raw technical reasons'
);

drawerOpenVarianceAssert(
    strpos($page, 'سجل حركات الدرج') !== false
        && strpos($page, 'اضغط على الحركة لعرض التفاصيل') !== false,
    'movements log must invite expand-for-details'
);

drawerOpenVarianceAssert(
    strpos($page, 'pr-walk-amount--add') === false
        && strpos($page, 'pr-walk-amount--sub') === false
        && strpos($page, 'pr-timeline-amount--') === false,
    'cash in/out rows must stay neutral; green/red only for over/short'
);

drawerOpenVarianceAssert(
    strpos($page, '$formatVarianceAmountDisplay') !== false
        && strpos($page, 'أكثر من المتوقع') !== false
        && strpos($page, 'أقل من المتوقع') !== false
        && strpos($page, 'data-testid="drawer-session-resolution-amount"') !== false
        && strpos($page, 'مبلغ الفرق') !== false,
    'resolution log must spell out over/under for admin readers'
);

$movementsPos = strpos($page, 'data-testid="drawer-session-movements"');
$overridePos = strpos($page, 'data-testid="drawer-override-periods"');
drawerOpenVarianceAssert(
    $movementsPos !== false && $overridePos !== false && $movementsPos < $overridePos,
    'audit order must put drawer movements before override periods'
);

echo "drawer_session_open_variance_display_contract_test: PASS\n";
