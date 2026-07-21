<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/cash_shift_navigation.php';
require_once $root . '/classes/Pos/Service/CashShiftWorkspaceService.php';

function cashShiftContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$workspace = (string) file_get_contents($root . '/cash_flow_report.php');
$detail = (string) file_get_contents($root . '/drawer_session.php');
$redirect = (string) file_get_contents($root . '/closed_sessions.php');
$schema = (string) file_get_contents($root . '/classes/Sync/SchemaManager.php');
$close = (string) file_get_contents($root . '/classes/Pos/Service/ShiftCloseService.php');
$forceClose = (string) file_get_contents($root . '/classes/Pos/Service/ShiftSessionService.php');
$payload = (string) file_get_contents($root . '/classes/Sync/ShiftCloseSyncPayloadService.php');
$mirror = (string) file_get_contents($root . '/classes/Sync/CloudOperationalMirrorService.php');
$workspaceService = (string) file_get_contents($root . '/classes/Pos/Service/CashShiftWorkspaceService.php');
$routeManifest = require $root . '/config/rbac_route_manifest.php';

foreach (['TAB_OVERVIEW', 'TAB_SHIFTS', 'TAB_ORDERS', 'TAB_PAYMENTS', 'TAB_ITEMS', 'TAB_ATTENTION', 'TAB_MOVEMENTS', 'TAB_SETTINGS'] as $constant) {
    cashShiftContractAssert(strpos($workspaceService, "public const {$constant}") !== false, "workspace tab constant missing: {$constant}");
    cashShiftContractAssert(strpos($workspace, "CashShiftWorkspaceService::{$constant}") !== false, "workspace must consume tab constant: {$constant}");
}
$presets = (new CashShiftWorkspaceService())->datePresets('2026-07-15');
cashShiftContractAssert($presets['today']['date_from'] === '2026-07-15', 'today preset must use the current business day');
cashShiftContractAssert($presets['yesterday']['date_from'] === '2026-07-14', 'yesterday preset must be business-day relative');
cashShiftContractAssert($presets['last_7_days']['date_from'] === '2026-07-09', 'past-week preset must be inclusive of seven business dates');
cashShiftContractAssert($presets['month_to_date']['date_from'] === '2026-07-01', 'month preset must begin on the first business date of the month');
$focusedContext = (new CashShiftWorkspaceService())->normalizeContext(
    ['tab' => 'orders', 'focus' => 'order_cancelled'],
    ['date_from' => '2026-07-15', 'date_to' => '2026-07-15']
);
cashShiftContractAssert($focusedContext['focus'] === 'order_cancelled', 'order focus must be preserved on the orders tab');
$invalidFocusContext = (new CashShiftWorkspaceService())->normalizeContext(
    ['tab' => 'overview', 'focus' => 'order_cancelled'],
    ['date_from' => '2026-07-15', 'date_to' => '2026-07-15']
);
cashShiftContractAssert($invalidFocusContext['focus'] === '', 'tab-specific focus must not leak into another report tab');
cashShiftContractAssert(substr_count($workspace, 'data-testid="session-detail-link"') === 1, 'workspace should have one canonical shift list');
cashShiftContractAssert(strpos($workspace, 'scope') !== false && strpos($workspace, 'كل الفترات') !== false, 'backlog scope must be explicit');
cashShiftContractAssert(strpos($workspaceService, 'backlogOptions($context)') !== false, 'backlog badge and rows must share one filter mapping');
cashShiftContractAssert(strpos($workspaceService, 'sessionHasOverride') === false, 'backlog rendering must not use a per-row override query');
cashShiftContractAssert(strpos($detail, '$returnTo') !== false, 'drawer detail must preserve its workspace return location');
cashShiftContractAssert(strpos($detail, 'audit_page') !== false && strpos($detail, '<details class="pr-panel-body') !== false, 'override audit must be collapsible and paginated');
cashShiftContractAssert(
    strpos($redirect, "'status' => 'needs_review'") !== false && strpos($redirect, "'scope' => 'backlog'") !== false,
    'legacy shift page must redirect to the backlog'
);

cashShiftContractAssert(posmain_cash_shift_safe_return_to('https://evil.example/') === 'cash_flow_report.php?tab=shifts', 'external return_to must be rejected');
cashShiftContractAssert(posmain_cash_shift_safe_return_to('//evil.example/cash_flow_report.php') === 'cash_flow_report.php?tab=shifts', 'scheme-relative return_to must be rejected');
cashShiftContractAssert(
    posmain_cash_shift_safe_return_to('cash_flow_report.php?tab=shifts&status=open&unexpected=1')
        === 'cash_flow_report.php?tab=shifts&status=open',
    'return_to must preserve only workspace query keys'
);
cashShiftContractAssert(
    posmain_cash_shift_safe_return_to('cash_flow_report.php?tab=orders&focus=order_cancelled')
        === 'cash_flow_report.php?tab=orders&focus=order_cancelled',
    'return_to must preserve a valid focused report destination'
);

cashShiftContractAssert(strpos($schema, 'drawer_session_close_summaries') !== false, 'canonical close summary table must be planned');
cashShiftContractAssert(strpos($schema, 'legacy_closed_orders_archive') !== false, 'legacy close rows must have a staged archive path');
cashShiftContractAssert(strpos($close, "'drawer_session_id'") !== false && strpos($close, "'close_summary_id'") !== false, 'close result must expose canonical ids');
cashShiftContractAssert(strpos($close, 'recordShiftCloseSnapshot') !== false, 'normal close must enqueue the v2 sync snapshot in its transaction');
cashShiftContractAssert(strpos($forceClose, 'recordShiftCloseSnapshot') !== false, 'force close must enqueue the v2 sync snapshot in its transaction');
cashShiftContractAssert(strpos($forceClose, "['in_transaction' => true]") !== false, 'force close must keep drawer close and summary in the same transaction');
cashShiftContractAssert(strpos($payload, "'schema_version' => 2") !== false, 'new close sync payloads must be v2');
cashShiftContractAssert(strpos($payload, 'drawer_session_uuid') !== false, 'v2 close payload must be linked by drawer UUID');
cashShiftContractAssert(
    strpos($mirror, 'bool $allowAutoId = false') !== false
        && strpos($mirror, 'upsertRow($conn, \'drawer_session_close_summaries\', $summary, true)') !== false,
    'v2 restore must not reuse a remote auto-increment summary id'
);
cashShiftContractAssert(strpos($mirror, 'V1_SHIFT_CLOSE_DRAWER_RECOVERY_FAILED') !== false, 'unlinked v1 closes must fail explicitly when recovery is impossible');
cashShiftContractAssert(strpos($mirror, "'recovered_legacy_shift_close'") !== false, 'v1 restore must report its recovery result');

foreach (['print/closed_session_items.php', 'print/closed_session_receipt.php'] as $route) {
    $definition = $routeManifest[$route] ?? [];
    cashShiftContractAssert(($definition['lane'] ?? '') === 'erp', "{$route} must preserve the management session lane");
    cashShiftContractAssert(
        ($definition['any_of'] ?? []) === ['reports.cash_flow', 'pos.shift.close'],
        "{$route} must allow report viewers and shift closers without forcing POS logout"
    );
}

$runtimeFiles = [
    $root . '/classes/Pos/Service/ShiftCloseService.php',
    $root . '/classes/Pos/Service/ShiftSessionService.php',
    $root . '/classes/ShiftReport.php',
    $root . '/cash_flow_report.php',
    $root . '/closed_sessions.php',
    $root . '/drawer_session.php',
    $root . '/do_close_shift_z.php',
    $root . '/print/closed_session_receipt.php',
    $root . '/print/closed_session_items.php',
];
foreach ($runtimeFiles as $file) {
    $source = (string) file_get_contents($file);
    cashShiftContractAssert(strpos($source, 'closed_orders') === false, basename($file) . ' must not read or write the retired table');
}

echo "cash-shift-consolidation-contract-ok\n";
