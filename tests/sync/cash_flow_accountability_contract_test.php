<?php

function cashFlowAccountabilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "cash-flow-accountability-fail: {$message}\n");
        exit(1);
    }
}

$service = file_get_contents(__DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php');
$countService = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftCountService.php');
$workspace = file_get_contents(__DIR__ . '/../../cash_flow_report.php');
$detail = file_get_contents(__DIR__ . '/../../drawer_session.php');

foreach ([$service, $countService, $workspace, $detail] as $source) {
    cashFlowAccountabilityAssert($source !== false, 'accountability source readable');
}

foreach ([
    'shift_owner_user_id',
    'counted_by_user_id',
    'closed_by_user_id',
    'takeover_authorized_by_user_id',
    'preceding_session_id',
    'succeeding_session_id',
] as $field) {
    cashFlowAccountabilityAssert(strpos($service, "'{$field}'") !== false, "session projection exposes {$field}");
}

foreach ([
    'manager_approval_id',
    'manager_approval_status',
    'manager_approval_permission',
    'manager_approved_by_user_id',
    'manager_approved_by_name',
] as $field) {
    cashFlowAccountabilityAssert(strpos($service, "'{$field}'") !== false, "movement projection exposes {$field}");
}

cashFlowAccountabilityAssert(
    strpos($service, 'LEFT JOIN manager_approvals ma ON ma.id = dm.manager_approval_id') !== false,
    'movement projection resolves the stored manager approval'
);
cashFlowAccountabilityAssert(
    strpos($countService, 'LEFT JOIN users u ON u.id = dca.created_by') !== false,
    'count attempts resolve who performed each count'
);
cashFlowAccountabilityAssert(
    strpos($workspace, 'data-testid="cash-shift-accountability"') !== false,
    'main shift report renders accountability'
);
cashFlowAccountabilityAssert(
    strpos($workspace, 'اعتماد المدير') !== false,
    'main movement report renders manager approval'
);
cashFlowAccountabilityAssert(
    strpos($detail, 'data-testid="drawer-session-accountability"') !== false,
    'session detail renders the complete custody trail'
);

echo "cash-flow-accountability-ok\n";
